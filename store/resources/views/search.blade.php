@extends('layouts.app')
@section('content')
<div class="wrap">
    <form class="search-page-box" action="{{ route('search') }}" method="get" role="search" data-suggest="{{ route('search.suggest') }}">
        <svg viewBox="0 0 24 24" class="search-ico"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
        <input type="search" name="q" value="{{ $term }}" placeholder="What would you like to personalise?" autocomplete="off" @if(!$term) autofocus @endif aria-label="Search products">
        <div class="suggest" hidden></div>
    </form>

    @if($term === '')
        @if($popular->isNotEmpty())
            <h2 class="h-sm">Popular searches</h2>
            <div class="chips">@foreach($popular as $t)<a href="{{ route('search', ['q' => $t]) }}" class="chip">{{ $t }}</a>@endforeach</div>
        @endif
        <h2 class="h-sm">Browse categories</h2>
        <div class="chips">@foreach($menuCategories as $c)<a href="{{ $c->url() }}" class="chip">{{ $c->name }}</a>@endforeach</div>
    @else
        <header class="listing-head"><h1>Results for “{{ $term }}”</h1><p class="muted">{{ $products->total() }} found</p></header>
        @if($categories->isNotEmpty())
            <div class="chips">@foreach($categories as $c)<a href="{{ $c->url() }}" class="chip">{{ $c->name }}</a>@endforeach</div>
        @endif
        @if($products->isEmpty())
            <div class="empty"><p>Nothing matched “{{ $term }}”. Try a simpler word, like “frame” or “mug”.</p><a href="{{ url('shop') }}/" class="btn primary">Browse all gifts</a></div>
        @else
            <div class="grid">@foreach($products as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
            {{ $products->links() }}
        @endif
    @endif
</div>
@endsection
