@extends('admin.shell')
@section('admin')
<h1>Báo cáo vận hành</h1>
<form class="panel" method="get"><div class="fields">
<label>Kỳ<select name="range">@foreach(['custom'=>'Tùy chỉnh','today'=>'Hôm nay','yesterday'=>'Hôm qua','week'=>'7 ngày','days30'=>'30 ngày','month'=>'Tháng này','previous'=>'Tháng trước','quarter'=>'Quý này','year'=>'Năm nay'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['range'] ?? 'custom')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Từ ngày<input name="from" type="date" value="{{ $from }}"></label><label>Đến ngày<input name="to" type="date" value="{{ $to }}"></label>
<label>Giá vốn<select name="method"><option value="fifo" @selected($method==='fifo')>FIFO</option><option value="wac" @selected($method==='wac')>WAC</option></select></label>
<label>Gộp theo<select name="group">@foreach(['day'=>'Ngày','month'=>'Tháng','year'=>'Năm'] as $key=>$label)<option value="{{ $key }}" @selected(($filters['group'] ?? 'day')===$key)>{{ $label }}</option>@endforeach</select></label>
<label>Kho<select name="warehouse_id"><option value="">Tất cả</option>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(($filters['warehouse_id'] ?? null)==$w->id)>{{ $w->name }}</option>@endforeach</select></label>
<label>Danh mục<select name="category_id"><option value="">Tất cả</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(($filters['category_id'] ?? null)==$c->id)>{{ $c->name }}</option>@endforeach</select></label>
</div><button>Lọc</button> <button name="export" value="profit">Xuất lợi nhuận XLSX</button> <button name="export" value="inventory">Xuất tồn kho XLSX</button></form>
<div class="grid">
@foreach(['Doanh thu hàng thuần'=>$revenue,'Giá vốn'=>$cogs,'Lợi nhuận gộp'=>$gross,'Chi phí hoạt động'=>$expense,'Lợi nhuận hoạt động'=>$operating,'Lợi nhuận ròng'=>$net,'Tiền đã thu'=>$cash['payment'] ?? 0,'Tiền đã hoàn'=>$cash['refund'] ?? 0,'Giá trị tồn'=>$stock->sum($method.'_value')] as $label=>$value)
<div class="card">{{ $label }}<p class="metric">{{ number_format($value) }} ₫</p></div>@endforeach
<div class="card">Biên gộp / Biên ròng<p class="metric">{{ $revenue ? number_format(100*$gross/$revenue,1).'%' : '—' }} / {{ $revenue ? number_format(100*$net/$revenue,1).'%' : '—' }}</p></div>
<div class="card">Sản phẩm / Khách hàng<p class="metric">{{ $counts['products'] }} / {{ $counts['customers'] }}</p></div>
<div class="card">Tồn thực tế / Giữ chỗ<p class="metric">{{ $stock->sum('on_hand') }} / {{ $stock->sum('reserved') }}</p></div>
</div>
<p class="muted">Chi phí hoạt động, thu nhập khác, dòng tiền và số đơn lấy theo kỳ ở cấp toàn công ty. Lọc kho và danh mục áp dụng cho doanh thu, giá vốn và tồn. Lợi nhuận hoạt động và ròng dưới bộ lọc dùng chi phí toàn công ty.</p>
<h2>Đơn hàng theo trạng thái</h2><div class="inline">@forelse($orderStats as $status=>$qty)<span class="pill">{{ $status }} · {{ $qty }}</span>@empty<p>Chưa có đơn trong kỳ.</p>@endforelse</div>
<h2>Doanh thu và giá vốn theo kỳ</h2>
@if($series->isNotEmpty())<div class="panel"><canvas id="sales-chart" height="95" aria-label="Doanh thu, giá vốn và lợi nhuận theo kỳ"></canvas></div>@endif
<div class="table"><table><thead><tr><th>Kỳ</th><th>Doanh thu</th><th>Giá vốn</th><th>Lợi nhuận gộp</th></tr></thead><tbody>
@foreach($series as $period)<tr><td>{{ $period->period }}</td><td>{{ number_format($period->revenue) }}</td><td>{{ number_format($period->cogs) }}</td><td>{{ number_format($period->profit) }}</td></tr>@endforeach
</tbody></table></div>
<h2>Lợi nhuận theo SKU</h2><div class="table"><table><thead><tr><th>SKU</th><th>Sản phẩm</th><th>SL thuần</th><th>Doanh thu</th><th>Giá vốn</th><th>Lợi nhuận</th><th>Biên gộp</th></tr></thead><tbody>
@foreach($products as $p)<tr><td>{{ $p->sku }}</td><td>{{ $p->name }}</td><td>{{ $p->units }}</td><td>{{ number_format($p->revenue) }}</td><td>{{ number_format($p->cogs) }}</td><td>{{ number_format($p->profit) }}</td><td>{{ $p->revenue ? number_format($p->profit*100/$p->revenue,1).'%' : '—' }}</td></tr>@endforeach
</tbody></table></div>
<h2>Tồn hiện tại</h2><div class="table"><table><thead><tr><th>Kho</th><th>SKU</th><th>Thực tế</th><th>Giữ</th><th>Khả dụng</th><th>FIFO</th><th>WAC</th><th>Cảnh báo</th></tr></thead><tbody>
@foreach($stock as $s)<tr><td>{{ $s->code }}</td><td>{{ $s->sku }}</td><td>{{ $s->on_hand }}</td><td>{{ $s->reserved }}</td><td>{{ $s->on_hand-$s->reserved }}</td><td>{{ number_format($s->fifo_value) }}</td><td>{{ number_format($s->wac_value) }}</td><td>{{ $s->on_hand-$s->reserved <= $s->min_stock ? 'Tồn thấp' : '' }}</td></tr>@endforeach
</tbody></table></div>
@endsection
@push('scripts')
@if($series->isNotEmpty())
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.9/dist/chart.umd.min.js"></script>
<script>
if (window.Chart) {
    const rows = {{ \Illuminate\Support\Js::from($series->all()) }};
    new Chart(document.getElementById('sales-chart'), {
        type: 'line',
        data: {
            labels: rows.map(row => row.period),
            datasets: [
                {label:'Doanh thu', data: rows.map(row => Number(row.revenue)), borderColor:'#205741'},
                {label:'Giá vốn', data: rows.map(row => Number(row.cogs)), borderColor:'#a1583a'},
                {label:'Lợi nhuận gộp', data: rows.map(row => Number(row.profit)), borderColor:'#5645a3'}
            ]
        }
    });
}
</script>
@endif
@endpush
