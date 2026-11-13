@extends('layout')
@section('content')
<h1>Góc STEM</h1>
@forelse($posts as $post)<article class="card"><h2><a href="{{ route('article',$post->slug) }}">{{ $post->title }}</a></h2><p>{{ $post->seo_description }}</p></article>@empty<p>Chưa có bài viết.</p>@endforelse
{{ $posts->links('pagination::simple-default') }}
@endsection
