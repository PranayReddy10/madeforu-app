@extends('layouts.app')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Categories</h1></header>
    <div class="catx-list">
        @foreach($categories as $c)
            <section class="catx">
                <a href="{{ $c->url() }}" class="catx-head">
                    <span class="catx-img">@if($c->image)<img src="{{ $c->imageUrl() }}" alt="" loading="lazy" width="120" height="120">@else<span>{{ mb_substr($c->name, 0, 1) }}</span>@endif</span>
                    <span class="catx-text"><b>{{ $c->name }}</b>@if($c->products_count)<small>{{ $c->products_count }} {{ \Illuminate\Support\Str::plural('product', $c->products_count) }}</small>@endif</span>
                    <svg class="catx-go" viewBox="0 0 24 24" aria-hidden="true"><path d="M9 6l6 6-6 6"/></svg>
                </a>
                @if($c->children->isNotEmpty())
                    <div class="catx-subs">@foreach($c->children as $child)<a href="{{ $child->url() }}" class="chip">{{ $child->name }}</a>@endforeach</div>
                @endif
            </section>
        @endforeach
    </div>
</div>
@endsection
