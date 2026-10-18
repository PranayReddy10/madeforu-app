@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <header class="listing-head"><h1>Hi, {{ \Illuminate\Support\Str::before(auth()->user()->name, ' ') }}</h1></header>
    <div class="menu-list">
        <a href="{{ route('wishlist') }}">Wishlist <span>›</span></a>
        <a href="{{ route('track') }}">Track an order <span>›</span></a>
        @if(auth()->user()->is_admin)<a href="{{ route('admin.dashboard') }}">Store admin <span>›</span></a>@endif
    </div>
    <h2 class="h-sm">Your orders</h2>
    @forelse($orders as $o)
        <a href="{{ route('account.order', $o->number) }}" class="order-row">
            <span><b>{{ $o->number }}</b><small class="muted">{{ $o->created_at->format('j M Y') }} · {{ $o->items->sum('qty') }} items</small></span>
            <span class="status s-{{ $o->status }}">{{ $o->statusLabel() }}</span>
            <b>{{ money($o->total) }}</b>
        </a>
    @empty
        <p class="muted">No orders yet.</p>
    @endforelse
    {{ $orders->links() }}
    <form method="post" action="{{ route('logout') }}" class="center">@csrf<button class="btn ghost">Sign out</button></form>
</div>
@endsection
