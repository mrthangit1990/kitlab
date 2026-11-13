@extends('admin.shell')
@section('admin')
@php($title=['purchase_orders'=>'Mua hàng & nhập khẩu','inventory'=>'Tồn kho','orders'=>'Đơn hàng','operating_expenses'=>'Chi phí & thu nhập khác'][$tab])
<h1>{{ $title }}</h1>
<p class="no-print"><a href="{{ route('admin.operations',['tab'=>$tab,'export'=>'xlsx']) }}">Xuất danh sách XLSX</a></p>
@if($tab==='purchase_orders')
@if(auth()->user()->canManage('purchasing'))
<details @if(!$detail) open @endif><summary>Tạo PO</summary>
<form method="post" action="{{ route('admin.purchasing') }}">@csrf<input type="hidden" name="action" value="create">
<div class="fields">
<label>Nhà cung cấp<select name="supplier_id" required>@foreach($suppliers as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
<label>Tiền tệ<input name="currency" value="CNY" required maxlength="3"></label><label>VND / đơn vị ngoại tệ<input name="exchange_rate" type="number" min="0.00000001" step="0.00000001" required value="3500"></label>
<label>Ngày mua<input name="purchase_date" type="date" required value="{{ today()->toDateString() }}"></label><label>Dự kiến đến<input name="expected_at" type="date"></label><label>Ghi chú<input name="notes"></label>
</div>
<div id="po-lines"><fieldset><legend>Dòng PO</legend><div class="fields">
<label>SKU<select name="items[0][variant_id]" required>@foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }} · {{ $v->name }}</option>@endforeach</select></label>
<label>Số lượng<input name="items[0][quantity]" type="number" min="1" required></label>
<label>Đơn giá<input name="items[0][unit_price]" type="number" min="0" step="0.0001" required></label>
<label>Chiết khấu/đv<input name="items[0][discount]" type="number" min="0" step="0.0001"></label>
</div></fieldset></div>
<template id="po-line-template"><fieldset><legend>Dòng PO</legend><div class="fields">
<label>SKU<select data-name="variant_id" required>@foreach($variants as $v)<option value="{{ $v->id }}">{{ $v->sku }} · {{ $v->name }}</option>@endforeach</select></label>
<label>Số lượng<input data-name="quantity" type="number" min="1" required></label><label>Đơn giá<input data-name="unit_price" type="number" min="0" step="0.0001" required></label>
<label>Chiết khấu/đv<input data-name="discount" type="number" min="0" step="0.0001"></label>
</div></fieldset></template>
<button type="button" data-po-add>Thêm dòng</button> <button>Tạo PO</button></form></details>
@endif
@if($detail)
<section class="panel"><h2>{{ $detail->number }} · {{ $detail->status }}</h2>
@foreach($lines as $i)<p>{{ $i->sku }} · {{ $i->received }}/{{ $i->quantity }} đã nhận · {{ $i->unit_price }} {{ $detail->currency }}/đv</p>@endforeach
<p>Tỷ giá PO {{ $detail->exchange_rate }}</p>
@if(auth()->user()->canManage('purchasing'))
<form class="inline" method="post" action="{{ route('admin.purchasing') }}">@csrf<input type="hidden" name="action" value="state"><input type="hidden" name="id" value="{{ $detail->id }}">
<label>Trạng thái<select name="status"><option value="pending">Chờ duyệt</option>@if(auth()->user()->canManage('approve_po'))<option value="approved">Duyệt</option>@endif<option value="ordered">Đã đặt</option><option value="cancelled">Hủy</option></select></label><button>Cập nhật PO</button></form>
<details><summary>Thêm lô vận chuyển</summary><form method="post" action="{{ route('admin.purchasing') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="action" value="shipment">
<div class="fields"><label>Kho đến<select name="warehouse_id">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>
<label>Xuất xứ<input name="origin" required></label><label>Đơn vị vận chuyển<input name="carrier" required></label><label>Phương thức<input name="method" required></label><label>Mã theo dõi<input name="tracking"></label><label>Dự kiến đến<input name="expected_at" type="date"></label></div><button>Tạo lô</button></form></details>
@if($shipments->where('status','!=','completed')->isNotEmpty())
<details><summary>Chi phí lô nhập</summary><form method="post" action="{{ route('admin.purchasing') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="action" value="expense">
<div class="fields"><label>Lô<select name="shipment_id">@foreach($shipments->where('status','!=','completed') as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach</select></label>
<label>Khoản mục<input name="category" required></label><label>Số tiền<input name="amount" type="number" min="0" step="0.0001" required></label>
<label>Tiền tệ<input name="currency" value="VND" required maxlength="3"></label><label>Tỷ giá<input name="exchange_rate" type="number" step="0.00000001" min="0.00000001" value="1" required></label>
<label>Thuế/chi phí được khấu trừ<select name="recoverable"><option value="0">Không</option><option value="1">Có</option></select></label>
<label>Phân bổ<select name="allocation"><option value="quantity">Số lượng</option><option value="value">Giá trị</option><option value="weight">Trọng lượng</option><option value="manual">Thủ công</option></select></label></div><button>Ghi chi phí lô</button></form></details>
@endif
@endif
@if(auth()->user()->canManage('inventory') && in_array($detail->status,['ordered','partial'],true))
<details><summary>Ghi phiếu nhập GRN</summary><form method="post" action="{{ route('admin.purchasing') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="action" value="receive">
<input type="hidden" name="reference" value="{{ old('reference','GRN-'.\Illuminate\Support\Str::ulid()) }}">
<div class="fields"><label>Kho<select name="warehouse_id">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>
<label>Lô vận chuyển<select name="shipment_id"><option value="">Không dùng lô</option>@foreach($shipments->where('status','!=','completed') as $s)<option value="{{ $s->id }}">{{ $s->code }}</option>@endforeach</select></label></div>
@foreach($lines as $i)
<fieldset><legend>{{ $i->sku }} · Còn {{ $i->quantity-$i->received }}</legend><div class="fields">
@foreach(['accepted'=>'Đạt','damaged'=>'Hỏng','missing'=>'Thiếu'] as $key=>$label)<label>{{ $label }}<input name="rows[{{ $i->id }}][{{ $key }}]" type="number" min="0" value="{{ old("rows.$i->id.$key",0) }}"></label>@endforeach
@foreach($expenses->where('allocation','manual')->where('recoverable',0) as $e)<label>{{ $e->category }} / {{ $e->code }} (VND)<input name="manual[{{ $e->id }}][{{ $i->id }}]" type="number" min="0" value="0"></label>@endforeach
</div></fieldset>@endforeach<button>Ghi phiếu nhập</button></form></details>
@endif
<h3>Lô & chi phí</h3>
@foreach($shipments as $s)<p>{{ $s->code }} · {{ $s->status }} · {{ $s->carrier }} {{ $s->tracking }}</p>@endforeach
@foreach($expenses as $e)<p>{{ $e->code }} · {{ $e->category }} · {{ $e->amount }} {{ $e->currency }} × {{ $e->exchange_rate }} · {{ $e->allocation }} @if($e->recoverable) · Được khấu trừ @endif</p>@endforeach
<h3>Phiếu nhập và giá vốn</h3>
@foreach($receipts as $grn)<details><summary>{{ $grn->reference }} · {{ $grn->created_at }}</summary>
@foreach($receiptLines->where('goods_receipt_id',$grn->id) as $line)
<p>{{ $line->sku }} · Đạt {{ $line->accepted }} · Hỏng {{ $line->damaged }} · Thiếu {{ $line->missing }} · Tiền hàng {{ number_format($line->purchase_vnd) }} ₫ · Chi phí {{ number_format($line->allocated_vnd) }} ₫
@if($line->accepted) · Giá vốn/đv {{ number_format(($line->purchase_vnd+$line->allocated_vnd)/$line->accepted,2) }} ₫ @endif</p>
@foreach($allocationRows->get($line->id,[]) as $a)<p class="muted">{{ $a->category }} · {{ number_format($a->amount_vnd) }} ₫</p>@endforeach
@endforeach
<button data-print>In phiếu</button></details>@endforeach
</section>
@endif
@endif
@if($tab==='inventory')
@if(auth()->user()->canManage('inventory'))
<form class="panel" method="post" action="{{ route('admin.inventory') }}">@csrf
<div class="fields">
<label>Nghiệp vụ<select name="action"><option value="in">Điều chỉnh tăng</option><option value="out">Điều chỉnh giảm</option><option value="transfer">Chuyển kho</option><option value="count">Kiểm đếm và ghi chênh lệch</option></select></label>
<label>SKU hoặc barcode<input name="lookup" required></label><label>Kho<select name="warehouse_id">@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>
<label>Kho đích<select name="destination"><option value="">Chọn khi chuyển kho</option>@foreach($warehouses as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach</select></label>
<label>Số lượng<input name="quantity" type="number" min="0" required></label><label>Đơn giá VND phần tăng<input name="unit_cost" type="number" min="0"></label></div><button>Ghi sổ</button>
</form>@endif
<h2>Sổ kho gần nhất</h2><div class="table"><table><thead><tr><th>Ngày</th><th>Kho / SKU</th><th>Loại</th><th>SL</th><th>FIFO</th><th>WAC</th><th>Tham chiếu</th><th>Người dùng</th></tr></thead><tbody>
@foreach($ledger as $entry)<tr><td>{{ $entry->created_at }}</td><td>{{ $entry->code }} / {{ $entry->sku }}</td><td>{{ $entry->kind }}</td><td>{{ $entry->quantity }}</td><td>{{ $entry->fifo_value }}</td><td>{{ $entry->wac_value }}</td><td>{{ $entry->reference }}</td><td>{{ $entry->user_id }}</td></tr>@endforeach
</tbody></table></div>
@endif
@if($tab==='orders' && $detail)
<section class="panel"><h2>{{ $detail->number }} · {{ $detail->status }}</h2>
<p>{{ $detail->name }} · {{ $detail->phone }} · {{ $detail->address }}</p>
<p>Tổng {{ number_format($detail->total) }} ₫ · Đã thu {{ number_format($paid) }} ₫ · Đã hoàn {{ number_format($refunded) }} ₫</p>
<button class="no-print" data-print>In chi tiết đơn</button>
@if(auth()->user()->canManage('orders'))
<form method="post" class="inline no-print" action="{{ route('admin.orders') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="action" value="state">
<label>Trạng thái<select name="status"><option value="confirmed">Xác nhận</option><option value="processing">Xử lý</option><option value="ready">Sẵn sàng giao</option><option value="completed">Hoàn thành</option><option value="cancelled">Hủy</option></select></label><button>Cập nhật</button></form>@endif
@foreach($lines as $i)
<p>{{ $i->sku }} · {{ $i->name }} · Đã xuất {{ $i->fulfilled }}/{{ $i->quantity }} · Trả {{ $i->returned }} · Giá {{ number_format($i->unit_price) }} ₫</p>
@if(auth()->user()->canManage('orders') && $i->fulfilled < $i->quantity && in_array($detail->status,['confirmed','processing','ready','shipping'],true))
<form class="inline no-print" method="post" action="{{ route('admin.orders') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="item_id" value="{{ $i->id }}">
<input type="hidden" name="reference" value="FF-{{ \Illuminate\Support\Str::ulid() }}"><label>Số lượng xuất<input name="quantity" type="number" min="1" max="{{ $i->quantity-$i->fulfilled }}" required></label><button name="action" value="fulfill">Xuất hàng</button></form>
@endif
@endforeach
@foreach($fulfillments as $f)
<p>{{ $f->reference }} · {{ $f->sku }} · {{ $f->quantity }} · FIFO {{ number_format($f->fifo_cogs) }} ₫ · WAC {{ number_format($f->wac_cogs) }} ₫</p>
@if(auth()->user()->canManage('orders'))
<form class="inline no-print" method="post" action="{{ route('admin.orders') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}"><input type="hidden" name="fulfillment_id" value="{{ $f->id }}">
<input type="hidden" name="reference" value="RT-{{ \Illuminate\Support\Str::ulid() }}"><label>Số lượng trả<input name="quantity" type="number" min="1" max="{{ $f->quantity }}" required></label>
<label>Kiểm định<select name="restock"><option value="0">Không nhập lại</option><option value="1">Đạt, nhập lại</option></select></label><button name="action" value="return">Ghi hàng trả</button></form>
@endif
@endforeach
@if(auth()->user()->canManage('finance'))
<form class="inline no-print" method="post" action="{{ route('admin.orders') }}">@csrf<input type="hidden" name="id" value="{{ $detail->id }}">
<label>Số tiền VND<input type="number" name="amount" min="1" required></label><label>Mã đối soát<input name="reference" required></label>
<button name="action" value="payment">Ghi đã thu</button><button name="action" value="refund">Ghi đã hoàn</button></form>
@endif
<h3>Lịch sử</h3>@foreach($history as $h)<p>{{ $h->created_at }} · {{ $h->status }}</p>@endforeach
</section>
@endif
@if($tab==='operating_expenses')
<form class="panel" method="post" action="{{ route('admin.expenses') }}">@csrf
<div class="fields"><label>Nhóm chi phí<select name="expense_category_id">@foreach($groups as $g)<option value="{{ $g->id }}">{{ $g->name }}</option>@endforeach</select></label>
<label>Diễn giải<input name="description" required></label><label>Số tiền<input name="amount" type="number" min="0.0001" step="0.0001" required></label>
<label>Tiền tệ<input name="currency" value="VND" required maxlength="3"></label><label>Tỷ giá<input name="exchange_rate" type="number" min="0.00000001" step="0.00000001" required value="1"></label>
<label>Ngày chứng từ<input type="date" name="transaction_date" value="{{ today()->toDateString() }}" required></label><label>Phương thức<input name="payment_method" required value="bank"></label></div><button name="action" value="create">Lưu bản nháp</button></form>
@if(auth()->user()->canManage('approve_expense'))
<form class="panel" method="post" action="{{ route('admin.expenses') }}">@csrf
<div class="fields"><label>Loại<select name="kind"><option value="other_income">Thu nhập khác</option><option value="other_expense">Chi phí khác</option></select></label>
<label>Số tiền VND<input name="amount" type="number" min="1" required></label><label>Chứng từ<input name="reference" required></label>
<label>Ngày<input name="transaction_date" type="date" value="{{ today()->toDateString() }}" required></label></div><button name="action" value="other">Ghi sổ</button></form>
@endif
@endif
<div class="table"><table><thead><tr><th>ID</th><th>Thông tin</th><th>Trạng thái / tồn</th><th>Ngày tạo</th></tr></thead><tbody>
@forelse($records as $row)<tr><td>{{ $row->id }}</td><td>
@if(isset($row->number))<a href="{{ route('admin.operations',['tab'=>$tab,'id'=>$row->id]) }}">{{ $row->number }}</a>
@elseif(isset($row->description)){{ $row->description }} · {{ number_format($row->amount_vnd) }} ₫
@else Kho #{{ $row->warehouse_id }} / Biến thể #{{ $row->variant_id }} @endif</td>
<td>{{ $row->status ?? ($row->on_hand.' thực tế / '.$row->reserved.' giữ') }}
@if($tab==='operating_expenses' && $row->status==='draft' && auth()->user()->canManage('approve_expense'))
<form method="post" action="{{ route('admin.expenses') }}">@csrf<input type="hidden" name="id" value="{{ $row->id }}"><button name="action" value="approve">Duyệt</button></form>@endif</td>
<td>{{ $row->created_at }}</td></tr>@empty<tr><td colspan="4">Chưa có dữ liệu.</td></tr>@endforelse
</tbody></table></div>{{ $records->links('pagination::simple-default') }}
@endsection
