@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <header class="listing-head"><h1>Track your order</h1></header>
    <form class="panel form-grid" method="get">
        <label>Order number<input name="number" value="{{ request('number') }}" required placeholder="MFU…"></label>
        <label>Mobile number<input name="phone" value="{{ request('phone') }}" required inputmode="tel"></label>
        <button class="btn primary span2">Track</button>
    </form>
    @if($order)@include('account.order-detail', ['order' => $order])@endif
</div>
@endsection
