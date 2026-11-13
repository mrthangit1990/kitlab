@extends('layout')
@section('content')
@if($page === 'gioi-thieu')
<h1>Về KITLAB</h1><p>KITLAB tuyển chọn đồ chơi lắp ráp, bộ học STEM và robot cho gia đình và trường học.</p>
@elseif($page === 'lien-he')
<h1>Liên hệ</h1><p>Thông tin hỗ trợ sẽ được công bố khi cửa hàng mở bán.</p>
@else
<h1>Mua hàng và thanh toán</h1><p>Bản chạy thử hỗ trợ đặt hàng COD và chuyển khoản khi quản trị đã cấu hình thông tin ngân hàng. Phí vận chuyển hiển thị trước khi đặt.</p>
@endif
@endsection
