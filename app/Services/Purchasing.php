<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Purchasing
{
    public function __construct(private Inventory $inventory) {}

    public function receive(int $poId, int $warehouse, ?int $shipmentId, array $rows, int $user, string $reference, array $manual = []): int
    {
        return DB::transaction(function () use ($poId, $warehouse, $shipmentId, $rows, $user, $reference, $manual) {
            $po = DB::table('purchase_orders')->where('id', $poId)->lockForUpdate()->first();
            $existing = DB::table('goods_receipts')->where(['purchase_order_id' => $poId, 'reference' => $reference])->value('id');
            if ($existing) {
                return $existing;
            }
            Books::require($po && in_array($po->status, ['ordered', 'partial'], true), 'PO phải đang đặt hàng hoặc nhập một phần.');
            $shipment = null;
            if ($shipmentId) {
                $shipment = DB::table('shipments')->where(['id' => $shipmentId, 'purchase_order_id' => $poId, 'warehouse_id' => $warehouse])->lockForUpdate()->first();
                Books::require($shipment && $shipment->status !== 'completed', 'Lô vận chuyển đã khóa hoặc sai kho.');
            }
            $poItems = DB::table('purchase_order_items as i')->join('product_variants as v', 'v.id', '=', 'i.variant_id')
                ->where('i.purchase_order_id', $poId)->orderBy('i.variant_id')->get(['i.*', 'v.weight_grams'])->keyBy('id');
            $lines = [];
            foreach ($poItems as $item) {
                $row = $rows[$item->id] ?? [];
                $accepted = (int) ($row['accepted'] ?? 0);
                $damaged = (int) ($row['damaged'] ?? 0);
                $missing = (int) ($row['missing'] ?? 0);
                Books::require(min($accepted, $damaged, $missing) >= 0 && $accepted + $damaged + $missing <= $item->quantity - $item->received, 'Số lượng GRN vượt phần còn lại.');
                if ($accepted + $damaged + $missing === 0) {
                    continue;
                }
                $unit = bcsub($item->unit_price, $item->discount, 4);
                // Cumulative rounding makes a split receipt equal a single receipt.
                $purchase = Books::vnd(bcmul($unit, (string) ($item->received + $accepted), 4), $po->exchange_rate)
                    - Books::vnd(bcmul($unit, (string) $item->received, 4), $po->exchange_rate);
                $lines[$item->id] = compact('accepted', 'damaged', 'missing', 'purchase');
            }
            Books::require(count($lines) > 0, 'GRN phải có ít nhất một dòng.');
            $grn = DB::table('goods_receipts')->insertGetId([
                'purchase_order_id' => $poId, 'warehouse_id' => $warehouse, 'shipment_id' => $shipmentId,
                'user_id' => $user, 'reference' => $reference,
            ]);
            $allocations = [];
            if ($shipment) {
                $expenses = DB::table('import_expenses')->where('shipment_id', $shipmentId)->orderBy('id')->get();
                foreach ($expenses as $expense) {
                    if ($expense->recoverable) {
                        continue;
                    }
                    $weights = [];
                    foreach ($lines as $itemId => $line) {
                        if ($line['accepted'] === 0) {
                            continue;
                        }
                        $weights[$itemId] = match ($expense->allocation) {
                            'value' => $line['purchase'],
                            'weight' => $line['accepted'] * $poItems[$itemId]->weight_grams,
                            'manual' => (int) ($manual[$expense->id][$itemId] ?? 0),
                            default => $line['accepted'],
                        };
                    }
                    $vnd = Books::vnd($expense->amount, $expense->exchange_rate);
                    $total = array_sum($weights);
                    Books::require($total > 0 || $vnd === 0, 'Thiếu cơ sở phân bổ chi phí.');
                    if ($expense->allocation === 'manual') {
                        Books::require($total === $vnd && min($weights ?: [0]) >= 0, 'Phân bổ thủ công phải khớp tổng VND.');
                    }
                    foreach ($weights as $itemId => $weight) {
                        $share = $total > 0 ? Books::portion($vnd, $weight, $total) : 0;
                        $allocations[$itemId][$expense->id] = $share;
                        $total -= $weight;
                        $vnd -= $share;
                    }
                }
            }
            foreach ($lines as $itemId => $line) {
                $allocated = array_sum($allocations[$itemId] ?? []);
                $lineId = DB::table('goods_receipt_items')->insertGetId([
                    'goods_receipt_id' => $grn, 'purchase_order_item_id' => $itemId,
                    'accepted' => $line['accepted'], 'damaged' => $line['damaged'], 'missing' => $line['missing'],
                    'purchase_vnd' => $line['purchase'], 'allocated_vnd' => $allocated,
                ]);
                foreach ($allocations[$itemId] ?? [] as $expenseId => $vnd) {
                    DB::table('import_expense_allocations')->insert([
                        'import_expense_id' => $expenseId, 'goods_receipt_item_id' => $lineId, 'amount_vnd' => $vnd,
                    ]);
                }
                if ($line['accepted'] > 0) {
                    DB::table('purchase_order_items')->where('id', $itemId)->increment('received', $line['accepted']);
                    $stock = $this->inventory->lock($warehouse, $poItems[$itemId]->variant_id);
                    $value = $line['purchase'] + $allocated;
                    $this->inventory->receive($stock, $line['accepted'], $value, $value, $user, $reference, $lineId);
                }
            }
            $open = DB::table('purchase_order_items')->where('purchase_order_id', $poId)->whereColumn('received', '<', 'quantity')->exists();
            DB::table('purchase_orders')->where('id', $poId)->update(['status' => $open ? 'partial' : 'received']);
            if ($shipment) {
                DB::table('shipments')->where('id', $shipmentId)->update(['status' => 'completed', 'arrived_at' => today()]);
            }
            Books::audit($user, 'receive', 'goods_receipts', $grn, ['reference' => $reference, 'po' => $poId]);
            return $grn;
        }, 3);
    }
}
