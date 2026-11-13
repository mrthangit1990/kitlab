@extends('admin.shell')
@section('admin')
<h1>Nhập / xuất Excel</h1>
<p class="inline"><a href="{{ route('admin.download','template') }}">Tải mẫu XLSX</a><a href="{{ route('admin.download','products') }}">Xuất sản phẩm XLSX</a></p>
<form class="panel inline" method="post" action="{{ route('admin.preview') }}" enctype="multipart/form-data">@csrf
<label>File<input type="file" name="file" accept=".xlsx" required></label><label>Chế độ<select name="mode"><option value="insert">Thêm SKU mới</option><option value="update">Cập nhật giá, mức tồn tối thiểu, khối lượng và thêm barcode</option></select></label><button>Kiểm tra tệp</button>
</form>
@if($job)<section class="panel"><h2>Phiên #{{ $job->id }} · {{ $job->status }}</h2><p>{{ $job->total }} dòng · {{ $job->failed }} lỗi · {{ $job->successful }} đã lưu</p>
@if($job->failed)<a href="{{ route('admin.download',['errors','job'=>$job->id]) }}">Tải lỗi XLSX</a><ul>@foreach($errorsList->take(30) as $error)<li>Dòng {{ $error->row_number }} · {{ $error->message }}</li>@endforeach</ul>@endif
@if($job->status==='preview' && $job->total>$job->failed)
<div class="table"><table><thead><tr><th>Dòng</th><th>SKU</th><th>Tên</th><th>Giá</th><th>Giá ưu đãi</th><th>Barcode</th></tr></thead><tbody>
@foreach(json_decode($job->payload,true) as $line=>$row)<tr><td>{{ $line }}</td><td>{{ $row['sku'] }}</td><td>{{ $row['name'] }}</td><td>{{ $row['price'] }}</td><td>{{ $row['sale_price'] }}</td><td>{{ $row['barcode'] }}</td></tr>@endforeach
</tbody></table></div><form method="post" action="{{ route('admin.confirm',$job->id) }}">@csrf<button>Xác nhận ghi các dòng hợp lệ</button></form>
@endif</section>@endif
<h2>Lịch sử nhập</h2>@foreach($jobs as $j)<p><a href="{{ route('admin.excel',['job'=>$j->id]) }}">{{ $j->filename }}</a> · {{ $j->created_at }} · {{ $j->status }}</p>@endforeach
@endsection
