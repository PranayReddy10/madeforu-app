@extends('layouts.app')
@section('content')
<div class="wrap narrow center pay-wait">
    <h1>Opening secure payment…</h1>
    <p>Order <b>{{ $order->number }}</b> · {{ money($order->total) }}</p>
    <button class="btn primary" id="rzp-open">Pay {{ money($order->total) }}</button>
    <p class="small muted">If the payment window did not open, tap the button above.</p>
    <form id="rzp-done" method="post" action="{{ route('checkout.verify') }}" hidden>
        @csrf
        <input name="razorpay_order_id"><input name="razorpay_payment_id"><input name="razorpay_signature">
    </form>
</div>
@endsection
@push('scripts')
<script src="https://checkout.razorpay.com/v1/checkout.js"></script>
<script>
(function () {
    var opts = {
        key: @json(config('store.razorpay.key')),
        amount: @json($rp['amount']),
        currency: 'INR',
        name: @json(config('store.name')),
        description: 'Order ' + @json($order->number),
        image: @json(asset('images/icon-192.png')),
        order_id: @json($rp['id']),
        prefill: { name: @json($order->name), email: @json($order->email), contact: @json('+91'.$order->phone) },
        theme: { color: '#f04577' },
        handler: function (r) {
            var f = document.getElementById('rzp-done');
            f.razorpay_order_id.value = r.razorpay_order_id;
            f.razorpay_payment_id.value = r.razorpay_payment_id;
            f.razorpay_signature.value = r.razorpay_signature;
            f.submit();
        }
    };
    var rzp = new Razorpay(opts);
    document.getElementById('rzp-open').onclick = function () { rzp.open(); };
    rzp.open();
})();
</script>
@endpush
