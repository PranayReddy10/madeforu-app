@extends('emails.layout')
@section('preheader'){{ $forAdmin ? 'New order '.$order->number.' — '.money($order->total) : 'Order '.$order->number.' confirmed — we\'re starting on your gift' }}@endsection
@section('body')
    @if($forAdmin)
        <h1 style="font-size:22px;margin:0 0 6px">New order {{ $order->number }} 🎉</h1>
        <p style="margin:0 0 6px">{{ $order->name }} · {{ $order->phone }} · {{ $order->email }}</p>
        @if($order->notes)<p style="background:#fff5f8;border-left:4px solid #f04577;padding:8px 12px;margin:10px 0"><b>Customer note:</b> {{ $order->notes }}</p>@endif
        @include('emails.partials.order-summary')
        @include('emails.partials.button', ['url' => route('admin.orders.show', $order), 'label' => 'Open in admin'])
    @else
        <h1 style="font-size:22px;margin:0 0 6px">Thank you, {{ \Illuminate\Support\Str::before($order->name, ' ') }}! 🎁</h1>
        <p style="margin:0 0 6px">Your order <b>{{ $order->number }}</b> is confirmed. We'll start making your gift now — if a photo needs adjusting, we'll message you before printing.</p>
        @include('emails.partials.order-summary')
        @include('emails.partials.button', ['url' => route('track', ['number' => $order->number, 'phone' => $order->phone]), 'label' => 'Track your order'])
        @if($order->invoice_number)<p style="font-size:13px;color:#4a5470">Your invoice {{ $order->invoice_number }} is attached. <a href="{{ \App\Services\Invoice::publicUrl($order) }}" style="color:#d42c5e">Download it here</a>.</p>@endif
    @endif
@endsection
