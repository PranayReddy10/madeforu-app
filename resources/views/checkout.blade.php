@extends('layouts.app')
@section('body_class', 'page-checkout')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Checkout</h1></header>
    <form method="post" action="{{ route('checkout.place') }}" class="checkout-layout" data-checkout data-capture="{{ route('checkout.capture') }}" @if(\App\Services\Cart::liveShipping()) data-quote="{{ route('checkout.quote') }}" @endif>
        @csrf
        <div class="checkout-main">
            <section class="panel">
                <h2 class="h-sm">Contact</h2>
                <div class="form-grid">
                    <label class="span2">Full name<input name="name" value="{{ old('name', $prefill['name']) }}" required autocomplete="name"></label>
                    <label>Mobile number<input name="phone" type="tel" value="{{ old('phone', $prefill['phone']) }}" required inputmode="tel" autocomplete="tel" placeholder="10-digit mobile"></label>
                    <label>Email<input name="email" type="email" value="{{ old('email', $prefill['email']) }}" required autocomplete="email"></label>
                </div>
            </section>
            <section class="panel">
                <h2 class="h-sm">Delivery address</h2>
                <div class="form-grid">
                    <label class="span2">House / flat, street<input name="address_line1" value="{{ old('address_line1', $prefill['address_line1']) }}" required autocomplete="address-line1"></label>
                    <label class="span2">Area, landmark (optional)<input name="address_line2" value="{{ old('address_line2', $prefill['address_line2']) }}" autocomplete="address-line2"></label>
                    <label>PIN code<input name="pincode" value="{{ old('pincode', $prefill['pincode']) }}" required inputmode="numeric" maxlength="6" autocomplete="postal-code" data-pincode data-check="{{ route('delivery.check') }}"><small class="pin-msg" data-pin-msg></small>@error('pincode')<span class="err">{{ $message }}</span>@enderror</label>
                    <label>City<input name="city" value="{{ old('city', $prefill['city']) }}" required autocomplete="address-level2" data-city></label>
                    <label class="span2">State
                        <select name="state" required autocomplete="address-level1" data-state>
                            <option value="">Select state</option>
                            @foreach($states as $s)<option @selected(old('state', $prefill['state']) === $s)>{{ $s }}</option>@endforeach
                        </select>
                    </label>
                    <label class="span2">Order notes (optional)<textarea name="notes" rows="2" placeholder="Anything we should know?">{{ old('notes') }}</textarea></label>
                </div>
            </section>
            <section class="panel">
                <h2 class="h-sm">Payment</h2>
                <div class="pay-options">
                    @php $names = ['razorpay' => 'Razorpay', 'cashfree' => 'Cashfree']; $chosen = old('payment_method', $gateways[0] ?? 'cod'); @endphp
                    @foreach($gateways as $g)
                        <label class="pay-opt"><input type="radio" name="payment_method" value="{{ $g }}" @checked($chosen === $g) data-total="{{ money($totals['total']) }}"><span><b>Pay online{{ count($gateways) > 1 ? ' · '.$names[$g] : '' }}</b><small>UPI, GPay, PhonePe, Paytm, cards, netbanking{{ count($gateways) > 1 ? '' : ' · secured by '.$names[$g] }}</small></span></label>
                    @endforeach
                    @if($codAllowed)
                        <label class="pay-opt"><input type="radio" name="payment_method" value="cod" @checked($chosen === 'cod') data-total="{{ money($codTotals['total']) }}"><span><b>Cash on delivery</b><small data-cod-note>@if($codTotals['cod_fee'] > 0)+{{ money($codTotals['cod_fee']) }} cash on delivery charges @else Pay when it arrives @endif</small></span></label>
                    @endif
                </div>
                @if(!$gateways && !$codAllowed)<p class="err">Payments are being set up — please contact us on WhatsApp to order.</p>@endif
                @error('payment_method')<p class="err">{{ $message }}</p>@enderror
            </section>
        </div>
        <aside class="summary">
            <h2 class="h-sm">Order summary</h2>
            @foreach($lines as $l)
                <div class="mini-line"><img src="{{ $l->image }}" alt="" width="48" height="48"><span>{{ $l->product->name }} <small class="muted">× {{ $l->qty }}@if($l->variant) · {{ implode(' / ', $l->variant->options) }}@endif</small>@include('partials.line-custom', ['l' => $l, 'compact' => true])</span><b>{{ money($l->total) }}</b></div>
            @endforeach
            <div data-totals="online" @if($chosen === 'cod') hidden @endif>@include('partials.totals', ['totals' => $totals])</div>
            <div data-totals="cod" @if($chosen !== 'cod') hidden @endif>@include('partials.totals', ['totals' => $codTotals])</div>
            <button class="btn primary block" data-place>Place order · <span data-place-total>{{ money($chosen === 'cod' ? $codTotals['total'] : $totals['total']) }}</span></button>
            <p class="small muted center">By placing your order you agree to our <a href="{{ collect($footerPages)->first(fn ($p) => str_contains($p['url'], 'term'))['url'] ?? url('/') }}">terms</a>. Personalised items are made just for you.</p>
        </aside>
    </form>
</div>
@endsection
