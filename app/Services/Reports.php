<?php
namespace App\Services;

use Illuminate\Support\Facades\DB;

final class Reports
{
    public function sales(string $from, string $to, string $method, array $filters = []): array
    {
        $cost = $method === 'fifo' ? 'fifo_cogs' : 'wac_cogs';
        $end = date('Y-m-d', strtotime($to.' +1 day'));
        $sold = DB::table('fulfillments as x')->where('x.created_at', '>=', $from)->where('x.created_at', '<', $end)
            ->selectRaw("x.order_item_id, x.created_at as event_at, x.quantity as units, x.net_sales as revenue, x.{$cost} as cogs");
        $returns = DB::table('return_items as x')->join('fulfillments as f', 'f.id', '=', 'x.fulfillment_id')
            ->where('x.created_at', '>=', $from)->where('x.created_at', '<', $end)
            ->selectRaw("f.order_item_id, x.created_at as event_at, -CAST(x.quantity AS SIGNED) as units, -x.net_sales as revenue, -x.{$cost} as cogs");
        $query = DB::query()->fromSub($sold->unionAll($returns), 'events')
            ->join('order_items as i', 'i.id', '=', 'events.order_item_id')
            ->join('orders as o', 'o.id', '=', 'i.order_id')
            ->join('product_variants as v', 'v.id', '=', 'i.variant_id')
            ->join('products as p', 'p.id', '=', 'v.product_id');
        foreach (['warehouse_id' => 'o.warehouse_id', 'product_id' => 'p.id', 'category_id' => 'p.category_id', 'brand_id' => 'p.brand_id'] as $key => $col) {
            if (!empty($filters[$key])) {
                $query->where($col, $filters[$key]);
            }
        }
        $products = (clone $query)->groupBy('i.variant_id', 'i.sku')
            ->selectRaw('i.variant_id, i.sku, MAX(i.name) as name, SUM(units) as units, SUM(revenue) as revenue, SUM(cogs) as cogs, SUM(revenue-cogs) as profit')
            ->orderByDesc('profit')->get();
        $format = match ($filters['group'] ?? 'day') { 'year' => '%Y', 'month' => '%Y-%m', default => '%Y-%m-%d' };
        $series = (clone $query)->selectRaw('DATE_FORMAT(event_at, ?) as period, SUM(revenue) as revenue, SUM(cogs) as cogs, SUM(revenue-cogs) as profit', [$format])
            ->groupBy('period')->orderBy('period')->get();
        $expense = (int) DB::table('operating_expenses')->where('status', 'approved')->whereBetween('transaction_date', [$from, $to])->sum('amount_vnd');
        $cash = DB::table('financial_transactions')->whereBetween('transaction_date', [$from, $to])->groupBy('kind')->selectRaw('kind, SUM(amount) as amount')->pluck('amount', 'kind');
        $revenue = (int) $products->sum('revenue');
        $cogs = (int) $products->sum('cogs');
        $gross = $revenue - $cogs;
        $operating = $gross - $expense;
        $net = $operating + (int) ($cash['other_income'] ?? 0) - (int) ($cash['other_expense'] ?? 0);
        return compact('products', 'series', 'cash', 'revenue', 'cogs', 'gross', 'expense', 'operating', 'net');
    }
}
