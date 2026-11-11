@extends('layouts.app')
@section('content')
@php($C = \App\Support\Campaign::class)
<section class="wrap camp-hero">
    <div class="camp-hero-in">
        <span class="eyebrow light">🪔 {{ $C::name() }} {{ $C::festival()->format('Y') }}</span>
        <h1>{{ $C::headline() }}</h1>
        @if($C::beforeDeadline())
            <p>Order by <b>{{ $C::deadline()->format('l, j F') }}</b> and it reaches them before {{ $C::name() }} ({{ $C::festival()->format('j M') }}).</p>
            <div class="camp-big-count" data-countdown="{{ $C::deadline()->toIso8601String() }}" data-long></div>
        @else
            <p>The order-by date has passed — message us on WhatsApp for a rush order and we'll do our best.</p>
        @endif
        @if($C::coupon() && $C::discount() > 0)
            <div class="camp-coupon">
                <span>{{ rtrim(rtrim(number_format($C::discount(), 1), '0'), '.') }}% off @if($C::minOrder() > 0) on orders over {{ money($C::minOrder()) }} @endif</span>
                <button type="button" class="camp-copy" data-copy="{{ $C::coupon() }}">{{ $C::coupon() }} <small>tap to copy</small></button>
            </div>
        @endif
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>{{ $C::name() }} gifts</h2><p>Every one personalised with your photos, names and wishes</p></div></div>
        <div class="pgrid">
            @foreach($products as $p)
                @include('partials.product-card', ['p' => $p])
            @endforeach
        </div>
    </div>
</section>

<section class="section">
    <div class="wrap">
        <div class="custom-band">
            <div>
                <span class="eyebrow light">Corporate & bulk {{ $C::name() }} gifts</span>
                <h2>Gifting your team, clients or family?</h2>
                <p>Personalised hampers, mugs and magnets in bulk with your logo or photos. Tell us how many and your budget — we'll send ideas and a price.</p>
                @if(config('store.whatsapp'))<a class="btn wa" href="https://wa.me/{{ config('store.whatsapp') }}?text={{ rawurlencode('Hi! I want '.$C::name().' gifts in bulk. Quantity: , Budget per gift: ₹') }}" rel="noopener">Chat on WhatsApp</a>@endif
            </div>
        </div>
    </div>
</section>
@endsection
