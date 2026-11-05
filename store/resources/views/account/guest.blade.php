@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <header class="listing-head"><h1>My account</h1></header>
    <div class="panel center">
        <p>Sign in to see your orders and wishlist.</p>
        <a href="{{ route('login') }}" class="btn primary">Sign in</a> <a href="{{ route('register') }}" class="btn ghost">Create account</a>
    </div>
    <div class="menu-list">
        <a href="{{ route('track') }}">Track an order <span>›</span></a>
        @foreach($footerPages as $fp)
            <a href="{{ $fp['url'] }}">{{ $fp['title'] }} <span>›</span></a>
        @endforeach
    </div>
</div>
@endsection
