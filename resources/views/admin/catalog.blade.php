@extends('admin.shell')
@section('admin')
<h1>{{ $title }}</h1>
@if($table==='product_variants')<form class="panel inline"><label>Quét hoặc nhập SKU/barcode<input name="q" value="{{ request('q') }}" autofocus></label><button>Tìm</button></form>@endif
<form class="panel" method="post" action="{{ route('admin.save',$table) }}">@csrf
<h2>{{ $edit ? 'Chỉnh sửa #'.$edit->id : 'Thêm mới' }}</h2>
@if($edit)<input type="hidden" name="id" value="{{ $edit->id }}">@endif
<div class="fields">@foreach($fields as $field=>$rule)
<label>{{ $field }}
@if(in_array($field,['description','body','notes','seo_description'],true))
<textarea name="{{ $field }}">{{ old($field,$edit?->$field ?? '') }}</textarea>
@elseif(str_contains($rule,'|boolean'))
<select name="{{ $field }}"><option value="1" @selected(old($field,$edit?->$field ?? 1)==1)>Có</option><option value="0" @selected(old($field,$edit?->$field ?? 1)==0)>Không</option></select>
@else
<input name="{{ $field }}" value="{{ old($field,$edit?->$field ?? (str_contains($rule,'required|integer|min:0') ? 0 : '')) }}" @required(str_starts_with($rule,'required'))>
@endif
</label>@endforeach</div>
@if($table==='product_variants')<p class="muted">Để trống SKU khi tạo để sinh mã tự động.</p>@endif
@if($table==='product_barcodes')<p class="muted">Để trống code để tạo barcode nội bộ cho Code 128 hoặc QR.</p>@endif
<button>Lưu</button> @if($edit)<a href="{{ route('admin.catalog',$table) }}">Thêm bản ghi mới</a>@endif
</form>
@if($table==='products' && $edit)
<form class="panel inline" method="post" enctype="multipart/form-data" action="{{ route('admin.image',$edit->id) }}">@csrf<label>Ảnh<input type="file" name="image" accept="image/jpeg,image/png,image/webp" required></label><label>Mô tả ảnh<input name="alt" required></label><button>Thêm ảnh</button></form>
@endif
<div class="table"><table><thead><tr><th>ID</th>@foreach(array_keys($fields) as $field)<th>{{ $field }}</th>@endforeach<th>Thao tác</th></tr></thead>
<tbody>@foreach($rows as $row)<tr><td>{{ $row->id }}</td>@foreach(array_keys($fields) as $field)<td>{{ \Illuminate\Support\Str::limit((string) ($row->$field ?? ''),70) }}</td>@endforeach
<td><a href="{{ route('admin.catalog',[$table,'edit'=>$row->id]) }}">Sửa</a> @if($table==='product_barcodes')<a href="{{ route('admin.label',$row->id) }}">In nhãn</a>@endif</td></tr>@endforeach</tbody></table></div>
{{ $rows->links('pagination::simple-default') }}
@endsection
