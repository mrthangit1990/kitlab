@extends('admin.shell')
@section('admin')
<h1>Quản trị KITLAB</h1>
<div class="grid">@foreach(config('admin') as $table=>[$label,$permission])@if(auth()->user()->canManage($permission))<a class="card" href="{{ route('admin.catalog',$table) }}">{{ $label }}</a>@endif @endforeach</div>
@endsection
