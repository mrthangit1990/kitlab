<!doctype html>
<html lang="vi">
<head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>@yield('title','KITLAB · Xưởng sáng tạo') </title>
<meta name="description" content="@yield('description','Đồ chơi lắp ráp, STEM và robot cho trẻ em Việt Nam')">
<link rel="canonical" href="{{ url()->current() }}">
@if(request()->is('admin*','tai-khoan*','gio-hang'))<meta name="robots" content="noindex,nofollow">@endif
<link rel="stylesheet" href="{{ asset('kitlab.css') }}">
<script defer src="{{ asset('kitlab.js') }}"></script>
@stack('head')
</head>
<body>
<header><div class="wrap nav"><a class="logo" href="{{ route('home') }}">KIT<span>LAB</span><small> XƯỞNG SÁNG TẠO</small></a>
<nav><a href="{{ route('home') }}#catalog">Sản phẩm</a><a href="{{ route('blog') }}">Góc STEM</a><a href="{{ route('cart') }}">Giỏ hàng ({{ array_sum(session('cart',[])) }})</a>
@auth <a href="{{ route('account') }}">Tài khoản</a> @if(auth()->user()->canManage('admin'))<a href="{{ route('admin.home') }}">Quản trị</a>@endif
<form action="{{ route('logout') }}" method="post">@csrf<button class="link">Thoát</button></form>
@else <a href="{{ route('login') }}">Đăng nhập</a> @endauth</nav></div></header>
<main class="wrap">
@if(session('notice'))<p class="notice" role="status">{{ session('notice') }}</p>@endif
@if($errors->any())<div class="notice error" role="alert"><strong>Kiểm tra lại thông tin</strong><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
@yield('content')
</main>
<footer><div class="wrap nav"><strong>KITLAB · Cùng con khám phá</strong><nav>
<a href="{{ route('info','gioi-thieu') }}">Giới thiệu</a><a href="{{ route('info','lien-he') }}">Liên hệ</a><a href="{{ route('info','chinh-sach') }}">Chính sách</a></nav></div></footer>
@stack('scripts')
</body>
</html>
