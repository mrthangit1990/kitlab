<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Inventory
{
    public function lock(int $warehouse, int $variant): object
    {
        Books::require(DB::transactionLevel() > 0, 'Thiếu giao dịch kho.');
        DB::table('inventory')->insertOrIgnore(['warehouse_id' => $warehouse, 'variant_id' => $variant]);
        return DB::table('inventory')->where(['warehouse_id' => $warehouse, 'variant_id' => $variant])->lockForUpdate()->first();
    }

    public function reserve(object $stock, int $delta, int $user, string $ref): void
    {
        $reserved = $stock->reserved + $delta;
        Books::require($reserved >= 0 && $reserved <= $stock->on_hand, 'Không đủ tồn khả dụng để giữ hàng.');
        DB::table('inventory')->where('id', $stock->id)->update(['reserved' => $reserved]);
        $this->movement($stock, $delta > 0 ? 'reserve' : 'release', 0, 0, 0, $user, $ref, 'Giữ chỗ '.$delta);
        $stock->reserved = $reserved;
    }

    public function receive(object $stock, int $qty, int $fifo, int $wac, int $user, string $ref, ?int $receipt = null, string $kind = 'receipt'): void
    {
        Books::require($qty > 0 && $fifo >= 0 && $wac >= 0, 'Số lượng hoặc giá trị nhập không hợp lệ.');
        DB::table('inventory_batches')->insert([
            'inventory_id' => $stock->id, 'goods_receipt_item_id' => $receipt,
            'original_quantity' => $qty, 'remaining' => $qty, 'original_value' => $fifo, 'remaining_value' => $fifo,
        ]);
        DB::table('inventory')->where('id', $stock->id)->update([
            'on_hand' => $stock->on_hand + $qty, 'wac_value' => (int) $stock->wac_value + $wac,
        ]);
        $this->movement($stock, $kind, $qty, $fifo, $wac, $user, $ref);
    }

    public function issue(object $stock, int $qty, int $user, string $ref, string $kind = 'issue'): array
    {
        Books::require($qty > 0 && $qty <= $stock->on_hand - $stock->reserved, 'Không đủ hàng khả dụng.');
        $left = $qty;
        $fifo = 0;
        $allocations = [];
        $layers = DB::table('inventory_batches')->where('inventory_id', $stock->id)->where('remaining', '>', 0)->orderBy('id')->lockForUpdate()->get();
        foreach ($layers as $layer) {
            $take = min($left, $layer->remaining);
            $value = Books::portion((int) $layer->remaining_value, $take, $layer->remaining);
            DB::table('inventory_batches')->where('id', $layer->id)->update([
                'remaining' => $layer->remaining - $take, 'remaining_value' => (int) $layer->remaining_value - $value,
            ]);
            $allocations[] = ['inventory_batch_id' => $layer->id, 'quantity' => $take, 'value' => $value];
            $fifo += $value;
            $left -= $take;
            if ($left === 0) {
                break;
            }
        }
        Books::require($left === 0, 'Tồn lô không khớp với sổ kho.');
        $wac = Books::portion((int) $stock->wac_value, $qty, $stock->on_hand);
        DB::table('inventory')->where('id', $stock->id)->update([
            'on_hand' => $stock->on_hand - $qty, 'wac_value' => (int) $stock->wac_value - $wac,
        ]);
        $this->movement($stock, $kind, -$qty, -$fifo, -$wac, $user, $ref);
        return compact('fifo', 'wac', 'allocations');
    }

    private function movement(object $stock, string $kind, int $qty, int $fifo, int $wac, int $user, string $reference, ?string $notes = null): void
    {
        $data = [
            'inventory_id' => $stock->id, 'user_id' => $user, 'kind' => $kind,
            'quantity' => $qty, 'fifo_value' => $fifo, 'wac_value' => $wac, 'reference' => $reference, 'notes' => $notes,
        ];
        $id = DB::table('inventory_transactions')->insertGetId($data);
        Books::audit($user, $kind, 'inventory_transactions', $id, $data);
    }
}
