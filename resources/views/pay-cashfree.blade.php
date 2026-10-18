@extends('layouts.app')
@section('content')
<div class="wrap narrow center pay-wait">
    <h1>Opening secure payment…</h1>
    <p>Order <b>{{ $order->number }}</b> · {{ money($order->total) }}</p>
    <button class="btn primary" id="cf-open">Pay {{ money($order->total) }}</button>
    <p class="small muted">UPI, cards, netbanking and wallets, powered by Cashfree. If the payment page did not open, tap the button above.</p>
</div>
@endsection
@push('scripts')
<script src="https://sdk.cashfree.com/js/v3/cashfree.js"></script>
<script>
(function () {
    var cashfree = Cashfree({ mode: @json($mode) });
    function open() { cashfree.checkout({ paymentSessionId: @json($sessionId), redirectTarget: '_self' }); }
    document.getElementById('cf-open').onclick = open;
    open();
})();
</script>
@endpush
