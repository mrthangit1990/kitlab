<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class Sales
{
    public function __construct(private Inventory $inventory) {}

    public function checkout(int $user, array $cart, array $delivery, string $reference): int
    {
        return DB::transaction(function () use ($user, $cart, $delivery, $reference) {
            $account = DB::table('users')->where('id', $user)->lockForUpdate()->first();
            $prior = DB::table('orders')->where(['number' => $reference, 'user_id' => $account->id])->first();
            if ($prior) {
                return $prior->id;
            }
            Books::require(count($cart) > 0, 'Giỏ hàng trống.');
            $warehouse = DB::table('warehouses')->where('id', $delivery['warehouse_id'])->where('active', true)->first();
            Books::require((bool) $warehouse, 'Kho không khả dụng.');
            $coupon = null;
            if (!empty($delivery['coupon'])) {
                $coupon = DB::table('coupons')->where(['code' => $delivery['coupon'], 'active' => true])
                    ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))->first();
                Books::require((bool) $coupon, 'Mã ưu đãi không hợp lệ.');
            }
            $shipping = config('kitlab.shipping');
            $id = DB::table('orders')->insertGetId([
                'number' => $reference, 'user_id' => $user, 'warehouse_id' => $warehouse->id,
                'coupon_id' => $coupon?->id, 'shipping' => $shipping, 'total' => 0,
                ...collect($delivery)->only(['name', 'phone', 'address', 'payment_method'])->all(),
            ]);
            ksort($cart, SORT_NUMERIC);
            $lines = [];
            foreach ($cart as $variant => $quantity) {
                $product = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
                    ->where('v.id', $variant)->where('p.published', true)->first(['v.*', 'p.name as product_name']);
                Books::require($product && $quantity > 0 && $quantity <= 99, 'Sản phẩm hoặc số lượng không hợp lệ.');
                $lines[$variant] = [
                    'quantity' => $quantity, 'unit_price' => (int) ($product->sale_price ?? $product->price),
                    'sku' => $product->sku, 'name' => $product->product_name.' / '.$product->name,
                ];
            }
            $gross = array_sum(array_map(fn ($line) => $line['quantity'] * $line['unit_price'], $lines));
            $discount = min((int) ($coupon?->amount ?? 0), $gross);
            $total = $gross - $discount + $shipping;
            foreach ($lines as $variant => $line) {
                $stock = $this->inventory->lock($warehouse->id, $variant);
                $this->inventory->reserve($stock, $line['quantity'], $user, $reference);
                $lineGross = $line['quantity'] * $line['unit_price'];
                $share = $gross ? Books::portion($discount, $lineGross, $gross) : 0;
                $gross -= $lineGross;
                $discount -= $share;
                $item = DB::table('order_items')->insertGetId(['order_id' => $id, 'variant_id' => $variant, 'discount' => $share, ...$line]);
                DB::table('stock_reservations')->insert(['order_item_id' => $item, 'inventory_id' => $stock->id, 'quantity' => $line['quantity']]);
            }
            DB::table('orders')->where('id', $id)->update(['total' => $total]);
            $this->status($id, 'pending', $user);
            return $id;
        }, 3);
    }

    public function fulfill(int $orderId, int $itemId, int $qty, int $user, string $reference): int
    {
        return DB::transaction(function () use ($orderId, $itemId, $qty, $user, $reference) {
            $order = $this->order($orderId);
            $prior = DB::table('fulfillments')->where(['reference' => $reference, 'order_item_id' => $itemId])->value('id');
            if ($prior) {
                return $prior;
            }
            Books::require(in_array($order->status, ['confirmed', 'processing', 'ready', 'shipping'], true), 'Trạng thái đơn không cho phép xuất hàng.');
            $item = DB::table('order_items')->where(['order_id' => $orderId, 'id' => $itemId])->first();
            Books::require($item && $qty > 0 && $qty <= $item->quantity - $item->fulfilled, 'Số lượng xuất vượt phần còn lại.');
            $stock = $this->inventory->lock($order->warehouse_id, $item->variant_id);
            $this->inventory->reserve($stock, -$qty, $user, $reference);
            $cost = $this->inventory->issue($stock, $qty, $user, $reference, 'fulfill');
            $net = $item->quantity * (int) $item->unit_price - (int) $item->discount;
            $delta = Books::portion($net, $item->fulfilled + $qty, $item->quantity) - Books::portion($net, $item->fulfilled, $item->quantity);
            $id = DB::table('fulfillments')->insertGetId([
                'order_item_id' => $itemId, 'quantity' => $qty, 'user_id' => $user, 'reference' => $reference,
                'net_sales' => $delta, 'fifo_cogs' => $cost['fifo'], 'wac_cogs' => $cost['wac'],
            ]);
            foreach ($cost['allocations'] as $allocation) {
                DB::table('cost_allocations')->insert(['fulfillment_id' => $id, ...$allocation]);
            }
            DB::table('stock_reservations')->where('order_item_id', $itemId)->decrement('quantity', $qty);
            DB::table('order_items')->where('id', $itemId)->increment('fulfilled', $qty);
            $this->status($orderId, 'shipping', $user);
            Books::audit($user, 'fulfill', 'fulfillments', $id, ['reference' => $reference, 'quantity' => $qty, 'net_sales' => $delta, 'fifo' => $cost['fifo'], 'wac' => $cost['wac']]);
            return $id;
        }, 3);
    }

    public function changeStatus(int $id, string $status, int $user): void
    {
        DB::transaction(function () use ($id, $status, $user) {
            $order = $this->order($id);
            $allowed = [
                'pending' => ['confirmed', 'cancelled'], 'confirmed' => ['processing', 'cancelled'],
                'processing' => ['ready', 'cancelled'], 'ready' => ['cancelled'], 'shipping' => ['completed'],
            ];
            Books::require(in_array($status, $allowed[$order->status] ?? [], true), 'Chuyển trạng thái không hợp lệ.');
            $items = DB::table('order_items')->where('order_id', $id)->orderBy('variant_id')->get();
            if ($status === 'completed') {
                Books::require($items->every(fn ($item) => $item->quantity === $item->fulfilled), 'Phải xuất đủ trước khi hoàn tất.');
            }
            if ($status === 'cancelled') {
                foreach ($items as $item) {
                    $stock = $this->inventory->lock($order->warehouse_id, $item->variant_id);
                    $this->inventory->reserve($stock, -$item->quantity, $user, $order->number);
                    DB::table('stock_reservations')->where('order_item_id', $item->id)->update(['quantity' => 0]);
                }
            }
            $this->status($id, $status, $user);
        }, 3);
    }

    public function returnUnits(int $orderId, int $fulfillmentId, int $qty, bool $restock, int $user, string $reference): int
    {
        return DB::transaction(function () use ($orderId, $fulfillmentId, $qty, $restock, $user, $reference) {
            $order = $this->order($orderId);
            $existing = DB::table('returns')->where(['order_id' => $orderId, 'reference' => $reference])->value('id');
            if ($existing) {
                return $existing;
            }
            $sale = DB::table('fulfillments as f')->join('order_items as i', 'i.id', '=', 'f.order_item_id')
                ->where(['f.id' => $fulfillmentId, 'i.order_id' => $orderId])->first(['f.*', 'i.variant_id']);
            Books::require((bool) $sale, 'Không tìm thấy lần xuất hàng.');
            $prior = (int) DB::table('return_items')->where('fulfillment_id', $fulfillmentId)->sum('quantity');
            Books::require($qty > 0 && $prior + $qty <= $sale->quantity, 'Số lượng trả vượt lượng đã xuất.');
            $portion = fn ($value) => Books::portion((int) $value, $prior + $qty, $sale->quantity)
                - Books::portion((int) $value, $prior, $sale->quantity);
            $fifo = $restock ? $portion($sale->fifo_cogs) : 0;
            $wac = $restock ? $portion($sale->wac_cogs) : 0;
            $id = DB::table('returns')->insertGetId(['order_id' => $orderId, 'user_id' => $user, 'reference' => $reference]);
            DB::table('return_items')->insert([
                'return_id' => $id, 'fulfillment_id' => $fulfillmentId, 'quantity' => $qty, 'restocked' => $restock,
                'net_sales' => $portion($sale->net_sales), 'fifo_cogs' => $fifo, 'wac_cogs' => $wac,
            ]);
            DB::table('order_items')->where('id', $sale->order_item_id)->increment('returned', $qty);
            if ($restock) {
                $stock = $this->inventory->lock($order->warehouse_id, $sale->variant_id);
                $this->inventory->receive($stock, $qty, $fifo, $wac, $user, $reference, null, 'return');
            }
            if ($order->status === 'completed' && !DB::table('order_items')->where('order_id', $orderId)->whereColumn('returned', '<', 'quantity')->exists()) {
                $this->status($orderId, 'returned', $user);
            }
            Books::audit($user, 'return', 'returns', $id, ['quantity' => $qty, 'restock' => $restock]);
            return $id;
        }, 3);
    }

    public function cash(int $orderId, string $type, int $amount, string $reference, int $user): void
    {
        DB::transaction(function () use ($orderId, $type, $amount, $reference, $user) {
            $order = $this->order($orderId);
            $existing = DB::table($type === 'payment' ? 'payments' : 'refunds')->where('reference', $reference)->first();
            if ($existing) {
                Books::require($existing->order_id == $orderId && (int) $existing->amount === $amount, 'Mã đối soát đã được sử dụng.');
                return;
            }
            $paid = (int) DB::table('payments')->where('order_id', $orderId)->sum('amount');
            $refunds = (int) DB::table('refunds')->where('order_id', $orderId)->sum('amount');
            if ($type === 'payment') {
                Books::require($order->status !== 'cancelled' && $amount > 0 && $paid + $amount <= $order->total, 'Số tiền thu vượt giá trị đơn.');
                $table = 'payments';
                $extra = ['paid_at' => now(), 'method' => $order->payment_method];
            } else {
                $credit = (int) DB::table('return_items as ri')->join('returns as r', 'ri.return_id', '=', 'r.id')
                    ->where('r.order_id', $orderId)->sum('ri.net_sales');
                $limit = $order->status === 'cancelled' ? $paid : min($credit, $paid);
                Books::require($amount > 0 && $refunds + $amount <= $limit, 'Số tiền hoàn vượt phần có thể hoàn.');
                $table = 'refunds';
                $extra = ['refunded_at' => now()];
            }
            $id = DB::table($table)->insertGetId(['order_id' => $orderId, 'user_id' => $user, 'amount' => $amount, 'reference' => $reference, ...$extra]);
            DB::table('financial_transactions')->insert([
                'user_id' => $user, 'kind' => $type, 'reference' => $reference, 'amount' => $amount, 'transaction_date' => today(),
            ]);
            Books::audit($user, $type, $table, $id, ['order_id' => $orderId, 'amount' => $amount]);
        }, 3);
    }

    private function order(int $id): object
    {
        $order = DB::table('orders')->where('id', $id)->lockForUpdate()->first();
        abort_unless($order, 404);
        return $order;
    }

    private function status(int $id, string $status, int $user): void
    {
        DB::table('orders')->where('id', $id)->update(['status' => $status]);
        DB::table('order_status_histories')->insert(['order_id' => $id, 'user_id' => $user, 'status' => $status]);
        Books::audit($user, 'status', 'orders', $id, ['status' => $status]);
    }
}
