<?php
namespace App\Http\Controllers;

use App\Services\Books;
use App\Services\Inventory;
use App\Services\Purchasing;
use App\Services\Sales;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Operations
{
    private const TABS = ['purchase_orders'=>'purchasing','inventory'=>'inventory','orders'=>'orders','operating_expenses'=>'finance'];
    private const CASH = ['payment','refund'];

    public function screen(Request $r, string $tab)
    {
        $this->access($r,$tab);
        $id = $r->integer('id');
        $records = DB::table($tab)->latest('id')->simplePaginate(30)->withQueryString();
        $detail = $id ? DB::table($tab)->find($id) : null;
        $data = ['tab'=>$tab,'records'=>$records,'detail'=>$detail,
            'warehouses'=>DB::table('warehouses')->where('active',true)->get(),
            'variants'=>DB::table('product_variants')->orderBy('sku')->get(['id','sku','name']),
        ];
        if ($r->query('export') === 'xlsx') {
            $columns = match ($tab) {
                'purchase_orders'=>['number','supplier_id','currency','exchange_rate','purchase_date','expected_at','status','payment_status'],
                'orders'=>['number','user_id','warehouse_id','total','shipping','payment_method','status','created_at'],
                'inventory'=>['warehouse_id','variant_id','on_hand','reserved','wac_value','updated_at'],
                default=>['description','expense_category_id','currency','amount','exchange_rate','amount_vnd','status','transaction_date'],
            };
            return Spreadsheets::xlsx('kitlab-'.$tab.'.xlsx',$columns,DB::table($tab)->select($columns)->orderBy('id')->cursor());
        }
        if ($tab === 'purchase_orders') {
            $data += ['suppliers'=>DB::table('suppliers')->where('active',true)->get(),
                'lines'=>DB::table('purchase_order_items as i')->join('product_variants as v','v.id','=','i.variant_id')->where('i.purchase_order_id',$id)->get(['i.*','v.sku']),
                'shipments'=>DB::table('shipments')->where('purchase_order_id',$id)->get(),
                'receipts'=>DB::table('goods_receipts')->where('purchase_order_id',$id)->get(),
                'expenses'=>DB::table('import_expenses as e')->join('shipments as s','s.id','=','e.shipment_id')->where('s.purchase_order_id',$id)->get(['e.*','s.code'])];
        }
        if ($tab === 'purchase_orders' && $id) {
            $data['receiptLines'] = DB::table('goods_receipt_items as i')->join('goods_receipts as g','g.id','=','i.goods_receipt_id')
                ->join('purchase_order_items as p','p.id','=','i.purchase_order_item_id')
                ->join('product_variants as v','v.id','=','p.variant_id')->where('g.purchase_order_id',$id)
                ->orderBy('g.id')->get(['i.*','g.reference','v.sku']);
            $data['allocationRows'] = DB::table('import_expense_allocations as a')
                ->join('import_expenses as e','e.id','=','a.import_expense_id')->join('goods_receipt_items as i','i.id','=','a.goods_receipt_item_id')
                ->join('goods_receipts as g','g.id','=','i.goods_receipt_id')->where('g.purchase_order_id',$id)
                ->get(['a.*','e.category','g.reference'])->groupBy('goods_receipt_item_id');
        }
        if ($tab === 'orders') {
            $data += ['lines'=>DB::table('order_items')->where('order_id',$id)->get(),
                'fulfillments'=>DB::table('fulfillments as f')->join('order_items as i','i.id','=','f.order_item_id')->where('i.order_id',$id)->get(['f.*','i.sku']),
                'history'=>DB::table('order_status_histories')->where('order_id',$id)->get(),
                'paid'=>DB::table('payments')->where('order_id',$id)->sum('amount'),
                'refunded'=>DB::table('refunds')->where('order_id',$id)->sum('amount')];
        }
        if ($tab === 'operating_expenses') $data['groups'] = DB::table('expense_categories')->get();
        if ($tab === 'inventory') {
            $data['ledger'] = DB::table('inventory_transactions as t')->join('inventory as i','i.id','=','t.inventory_id')
                ->join('product_variants as v','v.id','=','i.variant_id')->join('warehouses as w','w.id','=','i.warehouse_id')
                ->latest('t.id')->limit(30)->get(['t.*','v.sku','w.code']);
        }
        return view('admin.operations',$data);
    }

    public function purchasing(Request $r, Purchasing $service)
    {
        $action = $r->validate(['action'=>'required|in:create,state,shipment,expense,receive'])['action'];
        $this->allow($r,$action === 'receive' ? 'inventory' : 'purchasing');
        $user = $r->user()->id;
        $id = $r->integer('id');
        if ($action === 'create') {
            $d = $r->validate(['supplier_id'=>'required|exists:suppliers,id','currency'=>'required|regex:/^[A-Z]{3}$/',
                'exchange_rate'=>'required|decimal:0,8|gt:0|max:100000','purchase_date'=>'required|date_format:Y-m-d',
                'expected_at'=>'nullable|date_format:Y-m-d','notes'=>'nullable|string|max:4000',
                'items'=>'required|array|min:1|max:100','items.*.variant_id'=>'required|distinct|exists:product_variants,id',
                'items.*.quantity'=>'required|integer|min:1|max:100000','items.*.unit_price'=>'required|decimal:0,4|min:0|max:1000000',
                'items.*.discount'=>'nullable|decimal:0,4|min:0']);
            $id = DB::transaction(function () use ($d,$user) {
                $items = $d['items'];
                unset($d['items']);
                $id = DB::table('purchase_orders')->insertGetId(['number'=>'PO-'.Str::ulid(),...$d]);
                foreach ($items as $item) {
                    $item['discount'] ??= 0;
                    Books::require(bccomp((string) $item['discount'],(string) $item['unit_price'],4) <= 0,'Chiết khấu vượt đơn giá.');
                    DB::table('purchase_order_items')->insert(['purchase_order_id'=>$id,...$item]);
                }
                Books::audit($user,'create','purchase_orders',$id,$d);
                return $id;
            },3);
        } elseif ($action === 'receive') {
            $d = $r->validate(['warehouse_id'=>'required|exists:warehouses,id','shipment_id'=>'nullable|exists:shipments,id','rows'=>'required|array|max:100',
                'rows.*.accepted'=>'nullable|integer|min:0|max:100000','rows.*.damaged'=>'nullable|integer|min:0|max:100000','rows.*.missing'=>'nullable|integer|min:0|max:100000',
                'manual'=>'nullable|array','manual.*.*'=>'nullable|integer|min:0|max:1000000000000','reference'=>'required|alpha_dash:ascii|max:80']);
            $service->receive($id,$d['warehouse_id'],$d['shipment_id'] ?? null,$d['rows'],$user,$d['reference'],$d['manual'] ?? []);
        } else {
            DB::transaction(function () use ($action,$id,$r,$user) {
                $po = DB::table('purchase_orders')->where('id',$id)->lockForUpdate()->first();
                abort_unless($po,404);
                if ($action === 'state') {
                    $d = $r->validate(['status'=>'required|in:pending,approved,ordered,cancelled']);
                    if ($d['status'] === 'approved') $this->allow($r,'approve_po');
                    $allowed = ['draft'=>['pending','cancelled'],'pending'=>['approved','cancelled'],'approved'=>['ordered','cancelled']];
                    Books::require(in_array($d['status'],$allowed[$po->status] ?? [],true),'Chuyển trạng thái PO không hợp lệ.');
                    DB::table('purchase_orders')->where('id',$id)->update($d);
                } elseif ($action === 'shipment') {
                    $d = $r->validate(['warehouse_id'=>'required|exists:warehouses,id','origin'=>'required|string|max:191','carrier'=>'required|string|max:191',
                        'method'=>'required|string|max:191','tracking'=>'nullable|string|max:191','expected_at'=>'nullable|date_format:Y-m-d']);
                    Books::require(in_array($po->status,['approved','ordered','partial'],true),'PO chưa sẵn sàng tạo lô.');
                    DB::table('shipments')->insert(['purchase_order_id'=>$id,'code'=>'SH-'.Str::ulid(),...$d]);
                } else {
                    $d = $r->validate(['shipment_id'=>'required|exists:shipments,id','category'=>'required|string|max:191',
                        'amount'=>'required|decimal:0,4|min:0|max:10000000','currency'=>'required|regex:/^[A-Z]{3}$/',
                        'exchange_rate'=>'required|decimal:0,8|gt:0|max:100000','recoverable'=>'required|boolean','allocation'=>'required|in:quantity,value,weight,manual']);
                    $shipment = DB::table('shipments')->where(['id'=>$d['shipment_id'],'purchase_order_id'=>$id])->lockForUpdate()->first();
                    Books::require($shipment && $shipment->status !== 'completed','Lô không hợp lệ hoặc đã ghi sổ.');
                    DB::table('import_expenses')->insert($d);
                }
                Books::audit($user,$action,'purchase_orders',$id,$d);
            },3);
        }
        return redirect()->route('admin.operations',['tab'=>'purchase_orders','id'=>$id])->with('notice','Đã lưu nghiệp vụ mua hàng.');
    }

    public function inventory(Request $r, Inventory $service)
    {
        $this->allow($r,'inventory');
        $d = $r->validate(['action'=>'required|in:in,out,transfer,count','warehouse_id'=>'required|exists:warehouses,id',
            'destination'=>'nullable|different:warehouse_id|exists:warehouses,id','lookup'=>'required|string|max:191',
            'quantity'=>'required|integer|min:0|max:1000000','unit_cost'=>'nullable|integer|min:0|max:1000000000']);
        $variant = DB::table('product_variants')->where('sku',$d['lookup'])->value('id')
            ?? DB::table('product_barcodes')->where('code',$d['lookup'])->value('variant_id');
        Books::require((bool) $variant,'Không tìm thấy SKU hoặc barcode.');
        $user = $r->user()->id;
        $ref = 'INV-'.Str::ulid();
        DB::transaction(function () use ($d,$variant,$user,$ref,$service) {
            $warehouse = (int) $d['warehouse_id'];
            $qty = (int) $d['quantity'];
            if ($d['action'] === 'transfer') {
                Books::require(!empty($d['destination']),'Chọn kho đích.');
                $destination = (int) $d['destination'];
                $ids = [$warehouse,$destination];
                sort($ids);
                foreach ($ids as $id) $stocks[$id] = $service->lock($id,$variant);
                $cost = $service->issue($stocks[$warehouse],$qty,$user,$ref,'transfer_out');
                $service->receive($stocks[$destination],$qty,$cost['fifo'],$cost['wac'],$user,$ref,null,'transfer_in');
                $id = DB::table('stock_transfers')->insertGetId(['from_id'=>$warehouse,'to_id'=>$destination,'user_id'=>$user,'reference'=>$ref]);
                DB::table('stock_transfer_items')->insert(['stock_transfer_id'=>$id,'variant_id'=>$variant,'quantity'=>$qty]);
                return;
            }
            $stock = $service->lock($warehouse,$variant);
            if ($d['action'] === 'count') {
                $id = DB::table('stock_counts')->insertGetId(['warehouse_id'=>$warehouse,'user_id'=>$user,'status'=>'posted','reference'=>$ref]);
                DB::table('stock_count_items')->insert(['stock_count_id'=>$id,'variant_id'=>$variant,'expected'=>$stock->on_hand,'counted'=>$qty]);
                $qty -= $stock->on_hand;
                if ($qty < 0) $service->issue($stock,-$qty,$user,$ref,'count_out');
                if ($qty > 0) $this->opening($d,$service,$stock,$qty,$user,$ref);
                Books::audit($user,'count','stock_counts',$id,['difference'=>$qty]);
            } elseif ($d['action'] === 'in') {
                $this->opening($d,$service,$stock,$qty,$user,$ref);
            } else {
                $service->issue($stock,$qty,$user,$ref,'adjust_out');
            }
        },3);
        return back()->with('notice','Đã ghi sổ kho.');
    }

    private function opening(array $d, Inventory $service, object $stock, int $qty, int $user, string $ref): void
    {
        Books::require(isset($d['unit_cost']),'Phải nhập đơn giá cho phần tăng tồn.');
        $cost = $qty * (int) $d['unit_cost'];
        $service->receive($stock,$qty,$cost,$cost,$user,$ref,null,'adjust_in');
    }

    public function orders(Request $r, Sales $sales)
    {
        $d = $r->validate(['id'=>'required|exists:orders,id','action'=>'required|in:state,fulfill,return,payment,refund',
            'status'=>'nullable|in:confirmed,processing,ready,completed,cancelled','item_id'=>'nullable|integer',
            'fulfillment_id'=>'nullable|integer','quantity'=>'nullable|integer|min:1|max:99','restock'=>'nullable|boolean',
            'amount'=>'nullable|integer|min:1|max:1000000000000','reference'=>'nullable|alpha_dash:ascii|max:80']);
        $action = $d['action'];
        $this->allow($r,in_array($action,self::CASH,true) ? 'finance' : 'orders');
        $id = $d['id'];
        $user = $r->user()->id;
        $ref = $d['reference'] ?? 'OP-'.Str::ulid();
        match ($action) {
            'state'=>$sales->changeStatus($id,$d['status'] ?? '',$user),
            'fulfill'=>$sales->fulfill($id,$d['item_id'] ?? 0,$d['quantity'] ?? 0,$user,$ref),
            'return'=>$sales->returnUnits($id,$d['fulfillment_id'] ?? 0,$d['quantity'] ?? 0,(bool) ($d['restock'] ?? false),$user,$ref),
            default=>$sales->cash($id,$action,$d['amount'] ?? 0,$ref,$user),
        };
        return back()->with('notice','Đã lưu nghiệp vụ bán hàng.');
    }

    public function expenses(Request $r)
    {
        $this->allow($r,'finance');
        $user = $r->user()->id;
        $action = $r->validate(['action'=>'required|in:create,approve,other'])['action'];
        DB::transaction(function () use ($r,$user,$action) {
            if ($action === 'approve') {
                $this->allow($r,'approve_expense');
                $d = $r->validate(['id'=>'required|exists:operating_expenses,id']);
                $row = DB::table('operating_expenses')->where('id',$d['id'])->lockForUpdate()->first();
                Books::require($row->status === 'draft','Chi phí đã được xử lý.');
                DB::table('operating_expenses')->where('id',$row->id)->update(['status'=>'approved']);
                DB::table('financial_transactions')->insert(['user_id'=>$user,'kind'=>'operating_expense','reference'=>'EXP-'.$row->id,'amount'=>$row->amount_vnd,'transaction_date'=>$row->transaction_date]);
                Books::audit($user,'approve','operating_expenses',$row->id,$d);
                return;
            }
            if ($action === 'other') {
                $this->allow($r,'approve_expense');
                $d = $r->validate(['kind'=>'required|in:other_income,other_expense','amount'=>'required|integer|min:1|max:1000000000000','transaction_date'=>'required|date_format:Y-m-d','reference'=>'required|alpha_dash:ascii|max:80']);
                $id = DB::table('financial_transactions')->insertGetId(['user_id'=>$user,...$d]);
                Books::audit($user,'record','financial_transactions',$id,$d);
                return;
            }
            $d = $r->validate(['expense_category_id'=>'required|exists:expense_categories,id','description'=>'required|string|max:191','amount'=>'required|decimal:0,4|gt:0|max:10000000',
                'currency'=>'required|regex:/^[A-Z]{3}$/','exchange_rate'=>'required|decimal:0,8|gt:0|max:100000','transaction_date'=>'required|date_format:Y-m-d','payment_method'=>'required|string|max:191']);
            $d['amount_vnd'] = Books::vnd($d['amount'],$d['exchange_rate']);
            $id = DB::table('operating_expenses')->insertGetId(['user_id'=>$user,...$d]);
            Books::audit($user,'create','operating_expenses',$id,$d);
        },3);
        return back()->with('notice','Đã lưu nghiệp vụ tài chính.');
    }

    private function access(Request $r, string $tab): void
    {
        abort_unless(isset(self::TABS[$tab]),404);
        $allowed = match ($tab) {
            'purchase_orders'=>['purchasing','inventory','finance'],
            'orders'=>['orders','support','finance'],
            'inventory'=>['inventory','orders','finance'],
            default=>[self::TABS[$tab]],
        };
        abort_unless(collect($allowed)->contains(fn ($p) => $r->user()->canManage($p)),403);
    }

    private function allow(Request $r, string $permission): void
    {
        abort_unless($r->user()->canManage($permission),403);
    }
}
