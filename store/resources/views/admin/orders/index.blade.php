@extends('admin.layout')
@section('title', 'Orders')
@section('content')
<form class="filters card" method="get">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Order no., phone, name, email">
    <select name="status">
        <option value="">All statuses</option>
        @foreach (\App\Models\Order::STATUSES as $k => $l)<option value="{{ $k }}" @selected(request('status') === $k)>{{ $l }}</option>@endforeach
    </select>
    <select name="payment_status">
        <option value="">Any payment</option>
        @foreach (['unpaid', 'paid', 'failed', 'refunded'] as $p)<option value="{{ $p }}" @selected(request('payment_status') === $p)>{{ ucfirst($p) }}</option>@endforeach
    </select>
    <button class="btn ghost">Filter</button>
</form>
<div class="card">
<table class="tbl">
    <thead><tr><th>Order</th><th>Date</th><th>Customer</th><th>Items</th><th>Total</th><th>Payment</th><th>Status</th></tr></thead>
    <tbody>
    @forelse ($orders as $o)
        <tr>
            <td><a href="{{ route('admin.orders.show', $o) }}"><strong>{{ $o->number }}</strong></a></td>
            <td class="nowrap">{{ $o->created_at->format('d M Y, H:i') }}</td>
            <td>{{ $o->name }}<br><small class="muted">{{ $o->phone }} · {{ $o->city }}</small></td>
            <td>{{ $o->items_count }}</td>
            <td>{{ money($o->total) }}</td>
            <td>{{ strtoupper($o->payment_method) }}<br><span class="badge {{ $o->payment_status === 'paid' ? 'ok' : ($o->payment_status === 'failed' ? 'err' : '') }}">{{ $o->payment_status }}</span></td>
            <td><span class="badge s-{{ $o->status }}">{{ $o->statusLabel() }}</span></td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No orders found.</td></tr>
    @endforelse
    </tbody>
</table>
{{ $orders->links('admin.pagination') }}
@if ($delhivery)
<section class="card narrow">
    <h2>Delhivery pickup</h2>
    <p class="muted small">{{ $awaitingPickup }} {{ \Illuminate\Support\Str::plural('parcel', $awaitingPickup) }} booked and waiting to be picked up.</p>
    <form method="post" action="{{ route('admin.orders.pickup') }}" class="row3">
        @csrf
        <label>Date <input type="date" name="pickup_date" value="{{ now()->hour >= 14 ? now()->addDay()->toDateString() : now()->toDateString() }}" min="{{ now()->toDateString() }}"></label>
        <label>Time <input type="time" name="pickup_time" value="15:00"></label>
        <label>Parcels <input type="number" name="count" min="1" value="{{ max(1, $awaitingPickup) }}"></label>
        <button class="btn">Request pickup</button>
    </form>
</section>
@endif
</div>
@endsection
