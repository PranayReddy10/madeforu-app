@extends('layouts.app')
@section('content')
<div class="wrap narrow paylink">
    <div class="paylink-card">
        <p class="eyebrow">Order {{ $order->number }}</p>
        <h1>Hi {{ \Illuminate\Support\Str::before(trim($order->name), ' ') ?: $order->name }} 👋</h1>
        <ul class="paylink-items">
            @foreach ($order->items as $i)
                <li><span>{{ $i->name }}@if ($i->options) <small class="muted">({{ implode(' / ', $i->options) }})</small>@endif × {{ $i->qty }}</span><b>{{ money($i->line_total) }}</b></li>
            @endforeach
        </ul>
        @if ($order->payment_status === 'paid')
            <p class="paylink-done">✓ This order is already paid — thank you! We’ll send tracking once it ships.</p>
        @elseif ($problem)
            <p class="paylink-note">This order can’t be paid online here right now. Please message us on WhatsApp and we’ll help.</p>
        @else
            <dl class="totals">
                <div class="grand"><dt>To pay</dt><dd>{{ money($amount) }}</dd></div>
            </dl>
            @if ($order->payment_method === 'cod' && (float) $order->cod_fee > 0)
                <p class="small muted">You save the {{ money($order->cod_fee) }} cash on delivery charge by paying now.</p>
            @endif
            <form method="post" action="{{ request()->fullUrl() }}">
                @csrf
                <button class="btn primary block">Pay {{ money($amount) }} securely</button>
            </form>
            <p class="small muted center">UPI, GPay, PhonePe, Paytm, cards and netbanking · secured by {{ in_array('cashfree', \App\Http\Controllers\CheckoutController::gateways(), true) && ! in_array('razorpay', \App\Http\Controllers\CheckoutController::gateways(), true) ? 'Cashfree' : 'Razorpay' }}</p>
        @endif
        <p class="small muted center">Delivering to {{ $order->city }} {{ $order->pincode }} · Questions? <a href="https://wa.me/{{ config('store.whatsapp') }}">WhatsApp us</a></p>
    </div>
</div>
@endsection
