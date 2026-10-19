@extends('layouts.app')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Your cart</h1></header>
    @if($lines->isEmpty())
        <div class="empty"><p>Your cart is empty.</p><a href="{{ url('shop') }}/" class="btn primary">Find a gift</a></div>
    @else
    <div class="cart-layout">
        <form method="post" action="{{ route('cart.update') }}" class="cart-lines">
            @csrf
            @foreach($lines as $l)
                <article class="line">
                    <a href="{{ $l->product->url() }}" class="line-img"><img src="{{ $l->image }}" alt="{{ $l->product->name }}" width="96" height="96" loading="lazy"></a>
                    <div class="line-body">
                        <a href="{{ $l->product->url() }}" class="line-title">{{ $l->product->name }}</a>
                        @if($l->variant)<p class="muted small">{{ $l->variant->label() }}</p>@endif
                        @include('partials.line-custom', ['l' => $l])
                        <div class="line-foot">
                            <div class="qty small" data-qty>
                                <button type="button" data-step="-1" aria-label="Decrease">−</button>
                                <input type="number" name="qty[{{ $l->id }}]" value="{{ $l->qty }}" min="0" max="99" inputmode="numeric" data-autosubmit aria-label="Quantity">
                                <button type="button" data-step="1" aria-label="Increase">+</button>
                            </div>
                            <b>{{ money($l->total) }}</b>
                        </div>
                        <button formaction="{{ route('cart.remove', $l->id) }}" class="link-btn small">Remove</button>
                    </div>
                </article>
            @endforeach
            <noscript><button class="btn ghost">Update cart</button></noscript>
        </form>

        <aside class="summary">
            @guest
                <div class="account-nudge">
                    <b>@include('partials.icon', ['i' => 'gift']) Create a free account</b>
                    <ul>
                        <li>@include('partials.icon', ['i' => 'check']) Track every order in one place</li>
                        <li>@include('partials.icon', ['i' => 'check']) Faster checkout — address saved</li>
                        <li>@include('partials.icon', ['i' => 'check']) Reorder your gifts &amp; get member offers</li>
                    </ul>
                    <div class="an-actions">
                        <a class="btn primary small" href="{{ route('register', ['next' => 'cart']) }}">Create account</a>
                        <a class="btn ghost small" href="{{ route('login', ['next' => 'cart']) }}">Log in</a>
                    </div>
                    <small class="muted">Your cart and photos stay saved.</small>
                </div>
            @endguest
            @if($totals['free_shipping_gap'] > 0)
                <div class="ship-meter"><p>Add <b>{{ money($totals['free_shipping_gap']) }}</b> more for free shipping</p><span style="--p: {{ min(100, round(100 * ($totals['subtotal'] - $totals['discount']) / max(1, setting('free_shipping_over')))) }}%"></span></div>
            @elseif($totals['shipping'] == 0)
                <div class="ship-meter done"><p>🎉 You get free shipping</p><span style="--p: 100%"></span></div>
            @endif
            @include('partials.totals', ['totals' => $totals])
            @if($totals['coupon_dropped'])<p class="coupon-msg err" role="alert">{{ $totals['coupon_dropped'] }} It has been removed.</p>@endif
            @if($totals['coupon'])
                <form method="post" action="{{ route('cart.coupon.remove') }}" class="coupon-on">@csrf Coupon <b>{{ $totals['coupon'] }}</b> applied <button class="link-btn">Remove</button></form>
                @if(session('coupon_ok'))<p class="coupon-msg ok" role="status">{{ session('coupon_ok') }}</p>@endif
            @elseif($w = $totals['coupon_waiting'])
                <form method="post" action="{{ route('cart.coupon.remove') }}" class="coupon-wait">@csrf
                    <span>🏷️ <b>{{ $w['code'] }}</b> is saved — add <b>{{ money($w['short']) }}</b> more to get {{ $w['off'] }} <small>(orders of {{ money($w['min']) }}+)</small></span>
                    <button class="link-btn">Remove</button>
                </form>
            @else
                @php($couponErr = session('coupon_error') ?: $errors->first('code'))
                <form method="post" action="{{ route('cart.coupon') }}" class="coupon @if($couponErr) has-err @endif">@csrf<input name="code" value="{{ old('code') }}" placeholder="Coupon code" aria-label="Coupon code" maxlength="40" autocomplete="off" @if($couponErr) aria-invalid="true" aria-describedby="coupon-err" @endif><button class="btn small">Apply</button></form>
                @if($couponErr)<p class="coupon-msg err" id="coupon-err" role="alert">{{ $couponErr }}</p>@endif
                @if($offers->isNotEmpty())
                    <div class="offers">
                        <p class="offers-h">🏷️ Available offers</p>
                        @foreach($offers as $o)
                            <form method="post" action="{{ route('cart.coupon') }}" class="offer">@csrf<input type="hidden" name="code" value="{{ $o['code'] }}">
                                <span><b class="offer-code">{{ $o['code'] }}</b> {{ $o['text'] }}@if($o['short'] > 0)<small>Add {{ money($o['short']) }} more to use it</small>@endif</span>
                                <button class="btn small {{ $o['short'] > 0 ? 'ghost' : '' }}">{{ $o['short'] > 0 ? 'Save' : 'Apply' }}</button>
                            </form>
                        @endforeach
                    </div>
                @endif
            @endif
            @if($totals['prepaid_discount'] > 0)<p class="prepaid-note">💳 Includes {{ money($totals['prepaid_discount']) }} off for paying online (UPI, cards). Choosing cash on delivery at checkout removes it.</p>@endif
            <a href="{{ route('checkout') }}" class="btn primary block">Checkout · {{ money($totals['total']) }}</a>
            <a href="{{ url('shop') }}/" class="link-btn center">Continue shopping</a>
        </aside>
    </div>
    @endif
</div>
@endsection
