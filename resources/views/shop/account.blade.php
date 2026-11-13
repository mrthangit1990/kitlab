@extends('layout')
@section('content')
<h1>Tài khoản</h1>
<form method="post" class="panel inline" action="{{ route('profile') }}">@csrf<label>Họ tên<input name="name" required value="{{ auth()->user()->name }}"></label><button>Lưu</button></form>
<h2>Đơn hàng của tôi</h2>
@forelse($orders as $order)<p><a href="{{ route('order',$order->id) }}">{{ $order->number }}</a> · {{ $order->status }} · {{ number_format($order->total) }} ₫</p>@empty<p>Chưa có đơn hàng.</p>@endforelse
{{ $orders->links('pagination::simple-default') }}
@endsection
