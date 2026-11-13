@extends('layout')
@section('title',$post->seo_title ?: $post->title.' · KITLAB')
@section('description',$post->seo_description ?? '')
@section('content')
<article class="panel"><p class="eyebrow">Góc STEM · {{ substr($post->publish_at,0,10) }}</p><h1>{{ $post->title }}</h1><div class="pre">{{ $post->body }}</div></article>
@endsection
@push('head')
<meta property="og:title" content="{{ $post->seo_title ?: $post->title }}">
<meta property="og:description" content="{{ $post->seo_description ?? '' }}">
<script type="application/ld+json">{!! json_encode(['@context'=>'https://schema.org','@type'=>'Article','headline'=>$post->title,'datePublished'=>\Carbon\Carbon::parse($post->publish_at)->toIso8601String(),'url'=>route('article',$post->slug)],JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT) !!}</script>
@endpush
