@extends('layout')
@section('content')
<h1>Giỏ hàng</h1>
@php($subtotal=0)
@forelse($items as $item)
@php($subtotal+=($item->sale_price ?? $item->price)*$cart[$item->id])
<form class="panel inline" method="post" action="{{ route('cart.change') }}">@csrf
<input type="hidden" name="variant_id" value="{{ $item->id }}"><strong>{{ $item->product_name }} / {{ $item->name }}</strong>
<span>{{ number_format($item->sale_price ?? $item->price) }} ₫</span><label>Số lượng<input type="number" name="quantity" min="0" max="99" value="{{ $cart[$item->id] }}"></label>
<button>Cập nhật</button><button name="mode" value="set" data-remove-line>Xóa</button></form>
@empty<p>Giỏ hàng trống. <a href="{{ route('home') }}">Khám phá sản phẩm</a></p>@endforelse
@if($items->isNotEmpty())
<p>Tạm tính {{ number_format($subtotal) }} ₫ · Phí vận chuyển {{ number_format(config('kitlab.shipping')) }} ₫</p>
@auth
<form class="panel" method="post" action="{{ route('checkout') }}">@csrf
<input type="hidden" name="reference" value="{{ $reference }}">
<div class="fields"><label>Người nhận<input name="name" required value="{{ old('name',auth()->user()->name) }}"></label>
<label>Điện thoại<input name="phone" required value="{{ old('phone') }}"></label><label>Địa chỉ<input name="address" required value="{{ old('address') }}"></label>
<label>Kho giao hàng<select name="warehouse_id" required>@foreach($warehouses as $w)<option value="{{ $w->id }}" @selected(old('warehouse_id')==$w->id)>{{ $w->name }}</option>@endforeach</select></label>
<label>Mã ưu đãi<input name="coupon" value="{{ old('coupon') }}"></label>
<label>Thanh toán<select name="payment_method"><option value="cod">COD</option>@if(config('kitlab.bank'))<option value="bank">Chuyển khoản</option>@endif</select></label></div><button>Đặt hàng</button>
</form>
@else <a class="button" href="{{ route('login') }}">Đăng nhập để đặt hàng</a> @endauth
@endif
@endsection
