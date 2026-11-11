@extends('admin.layout')
@section('title', 'Home page banners')
@section('actions')<a class="btn" href="{{ route('admin.banners.create') }}">+ New banner</a>@endsection
@section('content')
<div class="card">
<table class="tbl">
    <thead><tr><th></th><th>Title</th><th>Link</th><th>Order</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($banners as $b)
        <tr>
            <td><img class="thumb wide" src="{{ media_url($b->image) }}" alt="" loading="lazy"></td>
            <td><a href="{{ route('admin.banners.edit', $b) }}"><strong>{{ $b->title }}</strong></a><br><small class="muted">{{ $b->subtitle }}</small></td>
            <td><small>{{ $b->link }}</small></td>
            <td>{{ $b->position }}</td>
            <td>@if ($b->is_active)<span class="badge ok">Active</span>@else<span class="badge">Hidden</span>@endif</td>
        </tr>
    @empty
        <tr><td colspan="5" class="muted">No banners of your own yet — the home page shows the built-in slides below.</td></tr>
    @endforelse
    </tbody>
</table>
</div>

@php $hasBanners = $banners->where('is_active', true)->isNotEmpty(); $on = fn ($k) => (string) setting('hero.'.$k, '1') !== '0'; @endphp
<form method="post" action="{{ route('admin.banners.builtin') }}" class="card">
    @csrf
    <h2>Built-in slides</h2>
    <p class="muted">Made automatically from your shop — no image needed.
        @if ($hasBanners && ! setting('hero.with_banners'))<b>Hidden right now because you have your own banners</b> (tick the last box to show both).@else Shown on the home page now.@endif
        @if (\App\Support\Campaign::active()) The {{ \App\Support\Campaign::name() }} slide comes first while the <a href="{{ route('admin.campaign.edit') }}">campaign</a> is on.@endif
    </p>
    @foreach (['brand' => ['“Your photos, turned into gifts they’ll treasure”', 'with photos of your featured products'], 'product' => ['Bestseller spotlight', 'your best-selling product, its price and a Personalise button'], 'offer' => ['Why MadeForU', 'free shipping over '.money((float) setting('free_shipping_over')).', cash on delivery, hand-made']] as $k => [$title, $what])
        <input type="hidden" name="{{ $k }}" value="0">
        <label class="check"><input type="checkbox" name="{{ $k }}" value="1" @checked($on($k))> <span><b>{{ $title }}</b> <small class="muted">— {{ $what }}</small></span></label>
    @endforeach
    <input type="hidden" name="with_banners" value="0">
    <label class="check"><input type="checkbox" name="with_banners" value="1" @checked(setting('hero.with_banners'))> Also show them after my own banners</label>
    <button class="btn primary">Save</button>
</form>
@endsection
