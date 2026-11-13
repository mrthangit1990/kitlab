<?php
namespace App\Http\Controllers;

use App\Services\Reports;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class Dashboard
{
    public function report(Request $r, Reports $reports)
    {
        abort_unless($r->user()->canManage('reports'),403);
        $filters = $r->validate(['from'=>'nullable|date_format:Y-m-d','to'=>'nullable|date_format:Y-m-d','method'=>'nullable|in:fifo,wac',
            'range'=>'nullable|in:today,yesterday,week,days30,month,previous,quarter,year,custom','group'=>'nullable|in:day,month,year',
            'category_id'=>'nullable|integer','warehouse_id'=>'nullable|integer','product_id'=>'nullable|integer','brand_id'=>'nullable|integer',
            'export'=>'nullable|in:profit,inventory','json'=>'nullable|boolean']);
        [$start,$finish] = match ($filters['range'] ?? 'custom') {
            'today'=>[today(),today()], 'yesterday'=>[today()->subDay(),today()->subDay()],
            'week'=>[today()->subDays(6),today()], 'days30'=>[today()->subDays(29),today()],
            'previous'=>[today()->subMonthNoOverflow()->startOfMonth(),today()->subMonthNoOverflow()->endOfMonth()],
            'quarter'=>[today()->startOfQuarter(),today()], 'year'=>[today()->startOfYear(),today()],
            default=>[today()->startOfMonth(),today()],
        };
        $custom = ($filters['range'] ?? 'custom') === 'custom';
        $from = $custom ? ($filters['from'] ?? $start->toDateString()) : $start->toDateString();
        $to = $custom ? ($filters['to'] ?? $finish->toDateString()) : $finish->toDateString();
        abort_if($from > $to,422,'Khoảng ngày không hợp lệ.');
        $method = $filters['method'] ?? 'fifo';
        $stock = DB::table('inventory as i')->join('warehouses as w','w.id','=','i.warehouse_id')
            ->join('product_variants as v','v.id','=','i.variant_id')->join('products as p','p.id','=','v.product_id');
        foreach (['warehouse_id'=>'w.id','category_id'=>'p.category_id','product_id'=>'p.id','brand_id'=>'p.brand_id'] as $field=>$col) {
            if (!empty($filters[$field])) $stock->where($col,$filters[$field]);
        }
        $stock->selectRaw('w.code, v.sku, i.on_hand, i.reserved, v.min_stock, i.wac_value, COALESCE((SELECT SUM(remaining_value) FROM inventory_batches WHERE inventory_id=i.id),0) as fifo_value');
        if (($filters['export'] ?? null) === 'inventory') return Spreadsheets::xlsx('kitlab-inventory.xlsx',['warehouse','sku','stock','reserved','min','wac','fifo'],$stock->cursor());
        $data = $reports->sales($from,$to,$method,$filters);
        if (($filters['export'] ?? null) === 'profit') return Spreadsheets::xlsx('kitlab-profit.xlsx',['variant_id','sku','name','units','revenue','cogs','profit'],$data['products']);
        if (!empty($filters['json'])) return response()->json($data);
        $orderStats = DB::table('orders')->where('created_at','>=',$from)->where('created_at','<',date('Y-m-d',strtotime($to.' +1 day')))
            ->groupBy('status')->selectRaw('status, COUNT(*) as qty')->pluck('qty','status');
        $counts = ['customers'=>DB::table('customers')->count(),'products'=>DB::table('products')->count()];
        $warehouses = DB::table('warehouses')->get();
        $categories = DB::table('categories')->get();
        return view('admin.report',[...$data,...compact('from','to','method','filters','orderStats','counts','warehouses','categories'),'stock'=>$stock->get()]);
    }
}
