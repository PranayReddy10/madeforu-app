@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <a href="{{ route('account') }}" class="link-btn">‹ All orders</a>
    @include('account.order-detail', ['order' => $order])
    @if($order->invoice_number)<p class="center"><a href="{{ route('invoice.download', $order->number) }}" class="btn ghost">Download invoice (PDF)</a></p>@endif
</div>
@endsection
