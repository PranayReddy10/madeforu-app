@extends('admin.layout')
@section('title', 'Products')
@section('actions')<a class="btn" href="{{ route('admin.products.create') }}">+ New product</a>@endsection
@section('content')
<form class="filters card" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or SKU">
    <select name="category">
        <option value="">All categories</option>
        @foreach ($categories as $c)
            <option value="{{ $c->id }}" @selected(request('category') == $c->id)>{{ str_repeat('— ', substr_count($c->path, '/')) }}{{ $c->name }}</option>
        @endforeach
    </select>
    <select name="active">
        <option value="">Active & hidden</option>
        <option value="1" @selected(request('active') === '1')>Active</option>
        <option value="0" @selected(request('active') === '0')>Hidden</option>
    </select>
    <button class="btn ghost">Filter</button>
</form>

<div class="card">
<table class="tbl">
    <thead><tr><th></th><th>Product</th><th>Price</th><th>Category</th><th>SEO</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($products as $p)
        @php $seoScore = \App\Http\Controllers\Admin\SeoController::score($p); @endphp
        <tr>
            <td><img class="thumb" src="{{ $p->mainImageUrl() }}" alt="" loading="lazy"></td>
            <td><a href="{{ route('admin.products.edit', $p) }}"><strong>{{ $p->name }}</strong></a><br><small class="muted">{{ $p->sku ?: '—' }} · {{ $p->type }}</small></td>
            <td>
                {{ money($p->currentPrice()) }}
                @if ($p->discountPercent())<br><small class="muted"><s>{{ money($p->regularPrice()) }}</s> −{{ $p->discountPercent() }}%</small>@endif
            </td>
            <td>{{ $p->primaryCategory?->name ?? '—' }}</td>
            <td><span class="score {{ $seoScore['score'] >= 80 ? 'good' : ($seoScore['score'] >= 50 ? 'mid' : 'bad') }}" title="{{ implode("\n", $seoScore['issues']) }}">{{ $seoScore['score'] }}</span></td>
            <td>
                @if ($p->is_active)<span class="badge ok">Active</span>@else<span class="badge">Hidden</span>@endif
                @if (! $p->in_stock)<span class="badge err">Out of stock</span>@endif
                @if ($p->is_featured)<span class="badge">★</span>@endif
            </td>
            <td class="nowrap"><a href="{{ $p->url() }}" target="_blank" rel="noopener">View</a></td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No products found.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $products->links('admin.pagination') }}
</div>
@endsection
