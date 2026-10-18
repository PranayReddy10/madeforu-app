@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <a href="{{ route('account') }}" class="link-btn">‹ All orders</a>
    @include('account.order-detail', ['order' => $order])
</div>
@endsection
