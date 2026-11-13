@extends('admin.shell')
@section('admin')
<h1>Phân quyền</h1>
<form class="panel inline" method="post">@csrf
<label>Tài khoản<select name="user_id">@foreach($users as $user)<option value="{{ $user->id }}">{{ $user->email }}</option>@endforeach</select></label>
<label>Vai trò<select name="role_id">@foreach($roles as $role)<option value="{{ $role->id }}">{{ $role->name }}</option>@endforeach</select></label>
<button name="action" value="add">Gán</button><button name="action" value="remove">Gỡ</button></form>
@foreach($assignments as $assignment)<p>{{ $assignment->email }} · {{ $assignment->name }}</p>@endforeach
@endsection
