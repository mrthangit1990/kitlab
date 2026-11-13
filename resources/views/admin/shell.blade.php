@extends('layout')
@section('title','Quản trị KITLAB')
@section('content')
<div class="panel no-print"><p class="eyebrow">KITLAB / Vận hành</p><nav>
<a href="{{ route('admin.home') }}">Trang quản trị</a>
@php($u=auth()->user())
@if($u->canManage('reports'))<a href="{{ route('admin.report') }}">Báo cáo</a>@endif
@if($u->canManage('purchasing') || $u->canManage('inventory') || $u->canManage('finance'))<a href="{{ route('admin.operations','purchase_orders') }}">Mua & nhập khẩu</a>@endif
@if($u->canManage('inventory') || $u->canManage('orders') || $u->canManage('finance'))<a href="{{ route('admin.operations','inventory') }}">Tồn kho</a>@endif
@if($u->canManage('orders') || $u->canManage('support') || $u->canManage('finance'))<a href="{{ route('admin.operations','orders') }}">Đơn hàng</a>@endif
@if($u->canManage('finance'))<a href="{{ route('admin.operations','operating_expenses') }}">Chi phí</a>@endif
@if($u->canManage('catalog'))<a href="{{ route('admin.excel') }}">Excel</a>@endif
@if($u->canManage('users'))<a href="{{ route('admin.roles') }}">Phân quyền</a>@endif
</nav></div>
@yield('admin')
@endsection
