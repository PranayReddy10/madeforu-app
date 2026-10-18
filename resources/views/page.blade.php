@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    @include('partials.breadcrumbs', ['crumbs' => [['Home', url('/')], [$page->title, $page->url()]]])
    <article class="prose page-body">
        <h1>{{ $page->title }}</h1>
        {!! $page->body !!}
    </article>
</div>
@endsection
