@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <div class="thanks">
        <div class="tick">✓</div>
        <h1>Thank you, {{ \Illuminate\Support\Str::before($order->name, ' ') }}!</h1>
        <p>Your order <b>{{ $order->number }}</b> is {{ $order->payment_status === 'paid' ? 'paid and confirmed' : ($order->payment_method === 'cod' ? 'confirmed — pay on delivery' : 'received') }}. We've emailed the details to {{ $order->email }}.</p>
        <p class="muted">We'll start crafting it now. If a photo needs adjusting we'll message you on WhatsApp before printing.</p>
    </div>
    @include('account.order-detail', ['order' => $order])
    <div class="center"><a href="{{ url('shop') }}/" class="btn primary">Continue shopping</a> <a href="{{ route('track', ['number' => $order->number, 'phone' => $order->phone]) }}" class="btn ghost">Track order</a>
        @if($order->invoice_number) <a href="{{ route('invoice.download', $order->number) }}" class="btn ghost">Download invoice</a>@endif</div>
</div>
@endsection
