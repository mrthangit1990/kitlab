@extends('layout')
@section('title', $product->seo_title ?: $product->name.' · KITLAB')
@section('description', $product->seo_description ?: \Illuminate\Support\Str::limit($product->description ?? '',155))
@section('content')
<p><a href="{{ route('home') }}">Trang chủ</a> / {{ $product->name }}</p>
<h1>{{ $product->name }}</h1>
<div class="grid">@foreach($images as $image)<img class="photo" src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($image->path) }}" alt="{{ $image->alt }}" loading="lazy">@endforeach</div>
<p class="pre">{{ $product->description }}</p>
<p class="inline"><span class="pill">{{ $product->age }}+ tuổi</span><span class="pill">{{ $product->pieces }} chi tiết</span><span class="pill">{{ $product->material }}</span><span class="pill">{{ $product->origin }}</span></p>
@if($product->video_url)<p><a href="{{ $product->video_url }}" rel="noopener noreferrer">Xem video sản phẩm</a></p>@endif
<div class="grid">@foreach($product->variants as $v)<article class="card">
<h3>{{ $v->name }}</h3><p>SKU {{ $v->sku }} · Khả dụng {{ $stock[$v->id] ?? 0 }}</p>
@foreach($barcodes->get($v->id,[]) as $b)<small>{{ $b->code }} </small>@endforeach
<p>@if($v->sale_price !== null)<del>{{ number_format($v->price) }} ₫</del>@endif <strong>{{ number_format($v->sale_price ?? $v->price) }} ₫</strong></p>
@if(($stock[$v->id] ?? 0)>0)
<form class="inline" action="{{ route('cart.change') }}" method="post">@csrf
<input type="hidden" name="variant_id" value="{{ $v->id }}"><input type="hidden" name="mode" value="add">
<label>Số lượng<input type="number" name="quantity" value="1" min="1" max="99" required></label><button>Thêm vào giỏ</button></form>
@endif
</article>@endforeach</div>
<h2>Cùng danh mục</h2><div class="inline">@foreach($related as $p)<a class="pill" href="{{ route('product',$p->slug) }}">{{ $p->name }}</a>@endforeach</div>
@endsection
@push('head')
@php
$offer = $product->variants->sortBy(fn ($v) => $v->sale_price ?? $v->price)->first();
$schema = ['@context'=>'https://schema.org','@type'=>'Product','name'=>$product->name,'description'=>$product->description,'url'=>route('product',$product->slug)];
if ($offer) {
    $schema['sku'] = $offer->sku;
    $schema['offers'] = ['@type'=>'Offer','priceCurrency'=>'VND','price'=>$offer->sale_price ?? $offer->price,'availability'=>($stock[$offer->id] ?? 0)>0?'https://schema.org/InStock':'https://schema.org/OutOfStock'];
}
@endphp
<meta property="og:title" content="{{ $product->seo_title ?: $product->name }}">
<meta property="og:description" content="{{ $product->seo_description ?: \Illuminate\Support\Str::limit($product->description ?? '',155) }}">
<script type="application/ld+json">{!! json_encode($schema,JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_INVALID_UTF8_SUBSTITUTE) !!}</script>
@endpush
