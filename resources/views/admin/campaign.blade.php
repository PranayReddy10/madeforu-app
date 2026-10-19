@extends('admin.layout')
@section('title', 'Campaign')
@section('actions')@if (\App\Support\Campaign::active())<a class="btn ghost" href="{{ \App\Support\Campaign::url() }}" target="_blank" rel="noopener">View landing page ↗</a>@endif @endsection
@section('content')
@php
    $v = fn ($k, $d = '') => old($k, setting('campaign.'.$k) ?: $d);
@endphp
<div class="grid-main">
<form method="post" action="{{ route('admin.campaign.update') }}" class="card" data-campaign-form>
    @csrf @method('PUT')
    <h2>Festival campaign</h2>
    <p class="muted">While it's on, every page shows a countdown strip with your coupon, products say whether they'll arrive before the festival, and a landing page lists your festive gifts. It ends by itself after the festival day.</p>
    <div class="preset-bar">
        <span class="muted small">Quick fill:</span>
        @foreach ($presets as $key => [$n, $fest, $dl, $code, $head])
            <button type="button" class="chip-btn" data-preset='@json(['name' => $n, 'festival' => $fest, 'deadline' => $dl, 'coupon' => $code, 'headline' => $head])'>{{ $n }} {{ \Illuminate\Support\Carbon::parse($fest)->format('Y') }}</button>
        @endforeach
    </div>
    <label class="check"><input type="hidden" name="enabled" value="0"><input type="checkbox" name="enabled" value="1" @checked(old('enabled', setting('campaign.enabled')))> <b>Campaign is on</b></label>
    <div class="row2">
        <label>Festival name <input name="name" value="{{ $v('name', 'Diwali') }}" required maxlength="40"></label>
        <label>Headline <small class="muted">landing page & strip</small><input name="headline" value="{{ $v('headline', 'Light up their Diwali with a gift made from your photos') }}" maxlength="120"></label>
    </div>
    <div class="row2">
        <label>Festival date <input type="date" name="festival" value="{{ $v('festival', '2026-11-08') }}" required></label>
        <label>Order by <small class="muted">last day for delivery in time</small><input type="date" name="deadline" value="{{ $v('deadline', '2026-11-02') }}" required></label>
    </div>
    <h3>Coupon <small class="muted">— created or updated for you, valid until the end of the festival day</small></h3>
    <div class="row3">
        <label>Code <input name="coupon" value="{{ $v('coupon', 'DIWALI10') }}" maxlength="30"></label>
        <label>Discount % <input type="number" step="0.5" min="0" max="90" name="discount" value="{{ $v('discount', '10') }}"></label>
        <label>On orders over (₹) <input type="number" step="1" min="0" name="min_order" value="{{ $v('min_order', '499') }}"></label>
    </div>
    <label>Gifts on the landing page
        <select name="category">
            <option value="">Featured & bestsellers</option>
            @foreach ($categories as $c)<option value="{{ $c->id }}" @selected((string) $v('category') === (string) $c->id)>{{ str_repeat('— ', substr_count($c->path, '/')) }}{{ $c->name }}</option>@endforeach
        </select>
    </label>
    <button class="btn primary">Save campaign</button>
</form>

<aside class="card">
    <h2>Share it</h2>
    @if (\App\Support\Campaign::active())
        <p>Landing page:<br><a href="{{ \App\Support\Campaign::url() }}" target="_blank" rel="noopener">{{ \App\Support\Campaign::url() }}</a></p>
        <p class="muted small">Use this link in your Instagram bio and stories, WhatsApp status and ads.</p>
        <p><a class="btn" href="{{ route('admin.users.whatsapp') }}">WhatsApp your past customers →</a></p>
    @else
        <p class="muted">Switch the campaign on to get the landing page link.</p>
    @endif
    <h3>Tips</h3>
    <ul class="small">
        <li>Order-by date = festival − (production days + about 6 delivery days).</li>
        <li>After the order-by date the strip changes to “last-minute? message us on WhatsApp”.</li>
        <li>Pick a category (e.g. Gift Combo) to make the landing page feel festive.</li>
    </ul>
</aside>
</div>
@endsection
