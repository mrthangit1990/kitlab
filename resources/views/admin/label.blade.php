@extends('admin.shell')
@section('admin')
<div class="panel"><strong>{{ $row->name }}</strong><p>{{ $row->sku }} · {{ number_format($row->sale_price ?? $row->price) }} ₫</p>
{!! $svg !!}<p>{{ $row->code }}</p><button data-print>In nhãn</button></div>
@endsection
