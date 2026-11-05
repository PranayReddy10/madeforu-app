@extends('emails.layout')
@php
    $first = \Illuminate\Support\Str::before($order->name, ' ');
    $copy = [
        'in_production' => ['We\'re making your gift ✂️', "Good news, {$first}! Your order {$order->number} is now being made by hand. We'll let you know as soon as it ships."],
        'delivered' => ['Delivered 💝', "Hi {$first}, your order {$order->number} has been delivered. We hope it made someone smile! If anything isn't right, just reply to this email or WhatsApp us."],
        'cancelled' => ['Your order has been cancelled', "Hi {$first}, your order {$order->number} has been cancelled.".($order->payment_status === 'paid' ? ' Your payment will be refunded to the original method within 5–7 working days.' : '').' If you didn\'t expect this, please contact us.'],
        'refunded' => ['Your refund is on its way', "Hi {$first}, we've refunded ".money($order->total)." for order {$order->number}. It usually reaches your account in 5–7 working days, depending on your bank."],
        'returned' => ['Your parcel came back to us', "Hi {$first}, the courier couldn't deliver order {$order->number} and it has been returned to us. Please reply or WhatsApp us to arrange delivery again."],
    ][$event] ?? ['Update on your order', "Hi {$first}, there's an update on your order {$order->number}."];
@endphp
@section('preheader'){{ $copy[1] }}@endsection
@section('body')
    <h1 style="font-size:22px;margin:0 0 8px">{{ $copy[0] }}</h1>
    <p>{{ $copy[1] }}</p>
    @if(in_array($event, ['in_production', 'delivered'], true))@include('emails.partials.order-summary')@endif
    @include('emails.partials.button', ['url' => route('track', ['number' => $order->number, 'phone' => $order->phone]), 'label' => 'View your order'])
    @if($event === 'delivered' && $order->invoice_number)<p style="font-size:13px;color:#4a5470">Need your invoice? <a href="{{ \App\Services\Invoice::publicUrl($order) }}" style="color:#d42c5e">Download {{ $order->invoice_number }}</a>.</p>@endif
@endsection
