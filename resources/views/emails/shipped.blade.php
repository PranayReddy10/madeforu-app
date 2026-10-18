@extends('emails.layout')
@section('preheader')Order {{ $order->number }} is on its way{{ $order->tracking_number ? ' — tracking '.$order->tracking_number : '' }}@endsection
@section('body')
    <h1 style="font-size:22px;margin:0 0 6px">Your order is on its way 🚚</h1>
    <p>Hi {{ \Illuminate\Support\Str::before($order->name, ' ') }}, order <b>{{ $order->number }}</b> has been shipped{{ $order->courier ? ' with '.$order->courier : '' }}.</p>
    @if($order->tracking_number)<p>Tracking number: <b>{{ $order->tracking_number }}</b></p>@endif
    @if($order->payment_method === 'cod' && $order->payment_status !== 'paid')
        <p style="background:#fff1d6;padding:10px 12px;border-radius:8px">Please keep <b>{{ money($order->total) }}</b> ready to pay on delivery.</p>
    @endif
    @include('emails.partials.button', ['url' => $order->trackingUrl() ?: route('track', ['number' => $order->number, 'phone' => $order->phone]), 'label' => 'Track your parcel'])
@endsection
