@extends('layout')
@section('content')
@if(!request()->query())
<section class="hero"><p class="eyebrow">KITLAB / Xưởng sáng tạo</p><h1>Lắp một ý tưởng.<br>Mở cả thế giới.</h1><p>Khám phá đồ chơi lắp ráp, bộ thí nghiệm STEM và robot dành cho nhà sáng chế nhỏ.</p><a class="button" href="#catalog">Khám phá sản phẩm</a></section>
<div class="inline">@foreach($categories as $category)<a class="pill" href="{{ route('home',['category'=>$category->id]) }}#catalog">{{ $category->name }}</a>@endforeach</div>
@endif
<h2 id="catalog">Khám phá bộ kit</h2>
<form class="panel" action="{{ route('home') }}#catalog">
<div class="fields">
<label>Tìm sản phẩm / SKU<input name="q" value="{{ request('q') }}"></label>
<label>Danh mục<select name="category"><option value="">Tất cả</option>@foreach($categories as $c)<option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ $c->name }}</option>@endforeach</select></label>
<label>Thương hiệu<select name="brand"><option value="">Tất cả</option>@foreach($brands as $b)<option value="{{ $b->id }}" @selected(request('brand') == $b->id)>{{ $b->name }}</option>@endforeach</select></label>
<label>Độ tuổi<input name="age" type="number" min="0" value="{{ request('age') }}"></label>
<label>Giá từ<input name="min" type="number" min="0" value="{{ request('min') }}"></label>
<label>Giá đến<input name="max" type="number" min="0" value="{{ request('max') }}"></label>
<label>Sắp xếp<select name="sort"><option value="new">Mới nhất</option><option value="price" @selected(request('sort')==='price')>Giá tăng</option><option value="price_desc" @selected(request('sort')==='price_desc')>Giá giảm</option></select></label>
<label>Còn hàng<select name="stock"><option value="">Tất cả</option><option value="1" @selected(request('stock')==='1')>Còn hàng</option></select></label>
</div><button>Lọc sản phẩm</button>
</form>
<div class="grid">@forelse($products as $p)
<article class="card"><div class="tile">KIT / {{ $p->age }}+</div><h3><a href="{{ route('product',$p->slug) }}">{{ $p->name }}</a></h3><p>Từ <strong>{{ number_format($p->display_price,0,',','.') }} ₫</strong></p>
@if($p->variants->count()===1)
<form action="{{ route('cart.change') }}" method="post">@csrf<input type="hidden" name="variant_id" value="{{ $p->variants->first()->id }}"><input type="hidden" name="quantity" value="1"><input type="hidden" name="mode" value="add"><button>Thêm vào giỏ</button></form>
@else <a href="{{ route('product',$p->slug) }}">Chọn biến thể</a> @endif
</article>@empty<p>Chưa có sản phẩm phù hợp.</p>@endforelse</div>
{{ $products->links('pagination::simple-default') }}
<h2>Góc STEM</h2><div class="grid">@foreach($posts as $p)<article class="card"><a href="{{ route('article',$p->slug) }}">{{ $p->title }}</a></article>@endforeach</div>
<form class="panel inline" action="{{ route('newsletter') }}" method="post">@csrf<label>Nhận bản tin KITLAB<input type="email" name="email" required></label><button>Đăng ký</button></form>
@endsection
