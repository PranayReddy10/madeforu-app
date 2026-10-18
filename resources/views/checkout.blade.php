@extends('layouts.app')
@section('body_class', 'page-checkout')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Checkout</h1></header>
    <form method="post" action="{{ route('checkout.place') }}" class="checkout-layout" data-checkout>
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
                    <label>PIN code<input name="pincode" value="{{ old('pincode', $prefill['pincode']) }}" required inputmode="numeric" maxlength="6" autocomplete="postal-code" data-pincode></label>
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
                    @if($razorpay)
                        <label class="pay-opt"><input type="radio" name="payment_method" value="razorpay" @checked(old('payment_method', 'razorpay') === 'razorpay') data-total="{{ money($totals['total']) }}"><span><b>Pay online</b><small>UPI, GPay, PhonePe, cards, netbanking</small></span></label>
                    @endif
                    @if($codAllowed)
                        <label class="pay-opt"><input type="radio" name="payment_method" value="cod" @checked(old('payment_method') === 'cod' || !$razorpay) data-total="{{ money($codTotals['total']) }}"><span><b>Cash on delivery</b><small>@if($codTotals['cod_fee'] > 0)+{{ money($codTotals['cod_fee']) }} handling fee @else Pay when it arrives @endif</small></span></label>
                    @endif
                </div>
                @error('payment_method')<p class="err">{{ $message }}</p>@enderror
            </section>
        </div>
        <aside class="summary">
            <h2 class="h-sm">Order summary</h2>
            @foreach($lines as $l)
                <div class="mini-line"><img src="{{ $l->image }}" alt="" width="48" height="48"><span>{{ $l->product->name }} <small class="muted">× {{ $l->qty }}@if($l->variant) · {{ implode(' / ', $l->variant->options) }}@endif</small></span><b>{{ money($l->total) }}</b></div>
            @endforeach
            @include('partials.totals', ['totals' => $totals])
            <button class="btn primary block" data-place>Place order · <span data-place-total>{{ money(old('payment_method') === 'cod' || !$razorpay ? $codTotals['total'] : $totals['total']) }}</span></button>
            <p class="small muted center">By placing your order you agree to our <a href="{{ url('terms-and-conditions') }}/">terms</a>. Personalised items are made just for you.</p>
        </aside>
    </form>
</div>
@endsection
