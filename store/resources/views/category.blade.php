@extends('layouts.app')
@section('content')
<div class="wrap">
    @php
        $crumbs = [['Home', url('/')]];
        if ($category) { foreach ($category->ancestors() as $a) $crumbs[] = [$a->name, $a->url()]; $crumbs[] = [$category->name, $category->url()]; }
        else $crumbs[] = ['Shop', url('shop').'/'];
    @endphp
    @include('partials.breadcrumbs', ['crumbs' => $crumbs])

    <header class="listing-head">
        <h1>{{ $category ? $category->name : 'All personalised gifts' }}</h1>
        <p class="muted">{{ $products->total() }} {{ \Illuminate\Support\Str::plural('product', $products->total()) }}</p>
    </header>

    @if($category && $category->children->isNotEmpty())
        <div class="chips">
            @foreach($category->children as $child)<a href="{{ $child->url() }}" class="chip">{{ $child->name }}</a>@endforeach
        </div>
    @endif

    <form class="toolbar" method="get">
        <label class="select">
            <span class="sr">Sort by</span>
            <select name="orderby" onchange="this.form.submit()">
                @foreach(['' => 'Popular', 'date' => 'Newest', 'price' => 'Price: low to high', 'price-desc' => 'Price: high to low', 'rating' => 'Top rated'] as $v => $l)
                    <option value="{{ $v }}" @selected(request('orderby') === $v)>{{ $l }}</option>
                @endforeach
            </select>
        </label>
        <label class="chip-toggle"><input type="checkbox" name="sale" value="1" @checked(request('sale')) onchange="this.form.submit()"> On offer</label>
        <span class="price-filter">
            <input type="number" name="min" placeholder="Min ₹" value="{{ request('min') }}" inputmode="numeric" aria-label="Minimum price">
            <input type="number" name="max" placeholder="Max ₹" value="{{ request('max') }}" inputmode="numeric" aria-label="Maximum price">
            <button class="btn small">Go</button>
        </span>
    </form>

    @if($products->isEmpty())
        <div class="empty"><p>No products here yet.</p><a href="{{ url('shop') }}/" class="btn primary">Browse all gifts</a></div>
    @else
        <div class="grid">
            @foreach($products as $p)@include('partials.product-card', ['p' => $p, 'eager' => $loop->index < 4])@endforeach
        </div>
        {{ $products->links() }}
    @endif

    @if($category && $products->currentPage() === 1 && ($category->description || $category->seo_content))
        <section class="seo-copy">
            @if($category->description)<div class="prose">{!! $category->description !!}</div>@endif
            @if($category->seo_content)<div class="prose">{!! $category->seo_content !!}</div>@endif
        </section>
    @endif
</div>
@endsection
