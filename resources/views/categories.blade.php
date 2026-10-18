@extends('layouts.app')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Categories</h1></header>
    <div class="cat-tiles">
        @foreach($categories as $c)
            <section class="cat-tile">
                <a href="{{ $c->url() }}" class="cat-tile-head">
                    <span class="bubble-img">@if($c->image)<img src="{{ $c->imageUrl() }}" alt="{{ $c->name }}" loading="lazy" width="120" height="120">@else<span class="bubble-letter">{{ mb_substr($c->name, 0, 1) }}</span>@endif</span>
                    <span><b>{{ $c->name }}</b>@if($c->products_count)<small>{{ $c->products_count }} {{ \Illuminate\Support\Str::plural('product', $c->products_count) }}</small>@endif</span>
                </a>
                @if($c->children->isNotEmpty())
                    <div class="chips">@foreach($c->children as $child)<a href="{{ $child->url() }}" class="chip">{{ $child->name }}</a>@endforeach</div>
                @endif
            </section>
        @endforeach
    </div>
</div>
@endsection
