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
                        @foreach($l->details as $d)
                            @if($d->type === 'images')
                                <div class="line-photos" title="{{ $d->label }}">
                                    @foreach($d->uploads as $u)<img src="{{ route('upload.show', ['token' => $u->token, 'thumb' => 1]) }}" alt="Your photo" width="40" height="40">@endforeach
                                </div>
                            @elseif($d->type === 'color')
                                <p class="small">{{ $d->label }}: <span class="swatch" style="background: {{ $d->value }}"></span></p>
                            @else
                                <p class="small">{{ $d->label }}: <b>{{ \Illuminate\Support\Str::limit($d->value, 80) }}</b></p>
                            @endif
                        @endforeach
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
            @if($totals['free_shipping_gap'] > 0)
                <div class="ship-meter"><p>Add <b>{{ money($totals['free_shipping_gap']) }}</b> more for free shipping</p><span style="--p: {{ min(100, round(100 * ($totals['subtotal'] - $totals['discount']) / max(1, setting('free_shipping_over')))) }}%"></span></div>
            @elseif($totals['shipping'] == 0)
                <div class="ship-meter done"><p>🎉 You get free shipping</p><span style="--p: 100%"></span></div>
            @endif
            @include('partials.totals', ['totals' => $totals])
            @if($totals['coupon'])
                <form method="post" action="{{ route('cart.coupon.remove') }}" class="coupon-on">@csrf Coupon <b>{{ $totals['coupon'] }}</b> applied <button class="link-btn">Remove</button></form>
            @else
                <form method="post" action="{{ route('cart.coupon') }}" class="coupon">@csrf<input name="code" placeholder="Coupon code" aria-label="Coupon code"><button class="btn small">Apply</button></form>
            @endif
            <a href="{{ route('checkout') }}" class="btn primary block">Checkout · {{ money($totals['total']) }}</a>
            <a href="{{ url('shop') }}/" class="link-btn center">Continue shopping</a>
        </aside>
    </div>
    @endif
</div>
@endsection
