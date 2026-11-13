@extends('layout')
@section('content')
<h1>{{ $register ? 'Đăng ký' : 'Đăng nhập' }}</h1>
<form class="panel" method="post">@csrf<div class="fields">
@if($register)<label>Họ tên<input name="name" required value="{{ old('name') }}" autocomplete="name"></label>@endif
<label>Email<input name="email" type="email" required value="{{ old('email') }}" autocomplete="email"></label>
<label>Mật khẩu<input name="password" type="password" required autocomplete="{{ $register?'new-password':'current-password' }}"></label>
@if($register)<label>Nhập lại mật khẩu<input name="password_confirmation" type="password" required autocomplete="new-password"></label>@endif
</div><button>{{ $register ? 'Tạo tài khoản' : 'Đăng nhập' }}</button></form>
<a href="{{ route($register?'login':'register') }}">{{ $register?'Đã có tài khoản':'Tạo tài khoản mới' }}</a>
@endsection
