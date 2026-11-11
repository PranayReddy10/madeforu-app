@extends('admin.layout')
@section('title', 'Categories')
@section('actions')<a class="btn" href="{{ route('admin.categories.create') }}">+ New category</a>@endsection
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th></th><th>Name</th><th>URL</th><th>Products</th><th>Order</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($categories as $c)
        <tr>
            <td>@if ($c->imageUrl())<img class="thumb" src="{{ $c->imageUrl() }}" alt="" loading="lazy">@endif</td>
            <td style="padding-left: {{ 8 + substr_count($c->path, '/') * 18 }}px"><a href="{{ route('admin.categories.edit', $c) }}"><strong>{{ $c->name }}</strong></a></td>
            <td><a href="{{ $c->url() }}" target="_blank" rel="noopener"><small>/product-category/{{ $c->path }}/</small></a></td>
            <td>{{ $c->products_count }}</td>
            <td>{{ $c->position }}</td>
            <td>
                @if ($c->is_active)<span class="badge ok">Active</span>@else<span class="badge">Hidden</span>@endif
                @if ($c->show_on_home)<span class="badge">Home</span>@endif @if ($c->show_in_menu === false)<span class="badge">Not in top menu</span>@endif
                @if (! $c->meta_description)<span class="badge warn" title="No meta description">SEO</span>@endif
            </td>
        </tr>
    @empty
        <tr><td colspan="6" class="muted">No categories yet. Run <code>php artisan store:import-woo</code> or add one.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
@endsection
