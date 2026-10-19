@extends('admin.layout')
@section('title', 'Products')
@section('actions')
    @if ($missingSkus)
        <form method="post" action="{{ route('admin.products.skus') }}">@csrf<button class="btn ghost" title="Makes MFU-MAG-007 style codes for every product and option without one; existing SKUs stay">Create {{ $missingSkus }} missing {{ $missingSkus === 1 ? 'SKU' : 'SKUs' }}</button></form>
    @endif
    <a class="btn" href="{{ route('admin.products.create') }}">+ New product</a>
@endsection
@section('content')
<form class="filters card" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search name or SKU">
    <select name="stock">
        <option value="">Any stock</option>
        @foreach (['low' => 'Low stock', 'out' => 'Out of stock', 'counted' => 'Stock counted'] as $k => $l)<option value="{{ $k }}" @selected(request('stock') === $k)>{{ $l }}</option>@endforeach
    </select>
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

<form id="bulk-form" method="post" action="{{ route('admin.products.personalisation') }}" class="card bulk-bar">
    @csrf
    <strong>Personalisation</strong>
    <select name="source" required>
        <option value="">Choose a setup…</option>
        <optgroup label="Presets">
            @foreach (\App\Support\Personalisation::presets() as $k => $p)<option value="{{ $k }}">{{ $p[0] }}</option>@endforeach
        </optgroup>
        <optgroup label="Copy from product">
            @foreach (\App\Models\Product::has('customizationFields')->orderBy('name')->get(['id', 'name']) as $src)<option value="product:{{ $src->id }}">{{ \Illuminate\Support\Str::limit($src->name, 60) }}</option>@endforeach
        </optgroup>
    </select>
    <span class="muted small">apply to</span>
    <select name="category">
        <option value="">the products ticked below</option>
        @foreach ($categories as $c)<option value="{{ $c->id }}">every product in {{ $c->name }}</option>@endforeach
    </select>
    <button class="btn" data-confirm="Replace the personalisation fields on these products? Past orders are not affected.">Apply</button>
</form>

<div class="card">
<table class="tbl">
    <thead><tr><th><input type="checkbox" data-check-all title="Select all"></th><th></th><th>Product</th><th>Price</th><th>Category</th><th>SEO</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($products as $p)
        @php $seoScore = \App\Http\Controllers\Admin\SeoController::score($p); @endphp
        <tr>
            <td><input type="checkbox" name="ids[]" value="{{ $p->id }}" form="bulk-form" data-check></td>
            <td><img class="thumb" src="{{ $p->thumbUrl() }}" alt="" loading="lazy"></td>
            <td><a href="{{ route('admin.products.edit', $p) }}"><strong>{{ $p->name }}</strong></a><br><small class="muted">{{ $p->sku ?: '—' }} · {{ $p->type }}</small>
                <br><small class="perso">{{ $p->customizationFields->map(fn ($f) => $f->type === 'images' ? '📷 '.$f->max_files.' '.\Illuminate\Support\Str::plural('photo', $f->max_files) : $f->label)->implode(' + ') ?: 'No personalisation' }}</small></td>
            <td>
                {{ money($p->currentPrice()) }}
                @if ($p->discountPercent())<br><small class="muted"><s>{{ money($p->regularPrice()) }}</s> −{{ $p->discountPercent() }}%</small>@endif
            </td>
            <td>{{ $p->primaryCategory?->name ?? '—' }}</td>
            <td><span class="score {{ $seoScore['score'] >= 80 ? 'good' : ($seoScore['score'] >= 50 ? 'mid' : 'bad') }}" title="{{ implode("\n", $seoScore['issues']) }}">{{ $seoScore['score'] }}</span></td>
            <td>
                @if ($p->is_active)<span class="badge ok">Active</span>@else<span class="badge">Hidden</span>@endif
                @if (! $p->in_stock)<span class="badge err">Out of stock</span>@endif
                @php $counted = $p->type === 'variable' ? $p->variants->whereNotNull('stock_qty') : collect($p->stock_qty !== null ? [$p] : []); @endphp
                @if ($counted->isNotEmpty())
                    @php $left = (int) $counted->sum('stock_qty'); @endphp
                    <span class="badge {{ $left <= \App\Services\Stock::lowThreshold() ? 'err' : '' }}" title="Units in stock">{{ $left }} left</span>
                @endif
                @if ($p->is_featured)<span class="badge">★</span>@endif
            </td>
            <td class="nowrap"><a href="{{ $p->url() }}" target="_blank" rel="noopener">View</a></td>
        </tr>
    @empty
        <tr><td colspan="8" class="muted">No products found.@unless (request()->query()) <a href="{{ route('admin.import') }}">Import them from WordPress →</a>@endunless</td></tr>
    @endforelse
    </tbody>
</table>
{{ $products->links('admin.pagination') }}
</div>
@endsection
