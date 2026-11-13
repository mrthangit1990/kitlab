@extends('layout')
@section('content')
<h1>Chi tiết đơn hàng</h1><p>{{ $order->number }} · {{ $order->status }}</p>
<div class="panel"><p>{{ $order->name }} · {{ $order->phone }} · {{ $order->address }}</p>
@foreach($items as $i)<p>{{ $i->sku }} · {{ $i->name }} × {{ $i->quantity }} · Đã xuất {{ $i->fulfilled }} · Đã trả {{ $i->returned }}</p>@endforeach
<p>Vận chuyển {{ number_format($order->shipping) }} ₫</p><strong>Tổng {{ number_format($order->total) }} ₫</strong>
@if($order->payment_method === 'bank')<p class="pre">{{ config('kitlab.bank') }}</p><p>Nội dung chuyển khoản {{ $order->number }}</p>@endif</div>
<h2>Lịch sử</h2>@foreach($history as $h)<p>{{ $h->created_at }} · {{ $h->status }}</p>@endforeach
@endsection
