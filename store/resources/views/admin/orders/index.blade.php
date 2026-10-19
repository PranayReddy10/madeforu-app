@extends('admin.layout')
@section('title', 'Orders')
@section('content')
@php
    $cur = request('status');
    $tabs = ['' => ['All', $counts->sum()], 'attention' => ['Needs attention', $attention]]
        + collect(\App\Models\Order::STATUSES)->mapWithKeys(fn ($l, $k) => [$k => [$l, $counts[$k] ?? 0]])->all();
    $warehouseProblem = $lastError && str_contains($lastError, 'pickup location');
@endphp

{{-- Delhivery refused a shipment because of the pickup location: explain and offer the fix right here --}}
@if ($delhivery && $lastError)
    <section class="card dv-alert">
        <div>
            <h2>⚠ Delhivery couldn’t book {{ $attention ? $attention.' '.\Illuminate\Support\Str::plural('order', $attention) : 'an order' }}</h2>
            <p class="small">{{ $lastError }}</p>
        </div>
        @if ($warehouseProblem)
            <details class="dv-fix" @if ($attention) open @endif>
                <summary class="btn small">Register my pickup address with Delhivery</summary>
                <form method="post" action="{{ route('admin.orders.warehouse') }}" class="dv-form">
                    @csrf
                    <p class="muted small">Or, if it already exists in Delhivery One → Settings → Pickup locations, copy that exact name into <a href="{{ route('admin.settings') }}#delhivery-shipping">Settings → Delhivery shipping</a>.</p>
                    <div class="row3">
                        <label>Pickup location name <input name="name" value="{{ setting('delhivery.pickup_location') ?: strtolower(config('store.name')) }}" required maxlength="60"></label>
                        <label>Phone <input name="phone" value="{{ config('store.phone') }}" required></label>
                        <label>PIN code <input name="pin" value="{{ setting('delhivery.pickup_pincode') ?: config('store.postcode') }}" required pattern="\d{6}"></label>
                    </div>
                    <label>Address <input name="address" value="{{ config('store.address') }}" required maxlength="250"></label>
                    <div class="row3">
                        <label>City <input name="city" value="{{ config('store.city') }}" required></label>
                        <label>State <input name="state" value="{{ config('store.region') }}" required></label>
                        <label>&nbsp;<button class="btn">Register &amp; save</button></label>
                    </div>
                </form>
            </details>
        @endif
    </section>
@endif

<nav class="ol-tabs">
    @foreach ($tabs as $k => [$label, $n])
        @if ($k === '' || $n || $cur === $k)
            <a href="{{ route('admin.orders.index', array_filter(['status' => $k, 'q' => request('q')])) }}" class="{{ (string) $cur === (string) $k ? 'on' : '' }} {{ $k === 'attention' && $n ? 'warn' : '' }}">{{ $label }} <b>{{ $n }}</b></a>
        @endif
    @endforeach
</nav>

<form class="ol-search card" method="get">
    @if ($cur)<input type="hidden" name="status" value="{{ $cur }}">@endif
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search order no., phone, name or email">
    <select name="payment_status" onchange="this.form.submit()">
        <option value="">Any payment</option>
        @foreach (['unpaid', 'paid', 'failed', 'refunded'] as $p)<option value="{{ $p }}" @selected(request('payment_status') === $p)>{{ ucfirst($p) }}</option>@endforeach
    </select>
    <button class="btn ghost">Search</button>
</form>

<form method="post" action="{{ route('admin.orders.bulk') }}" data-bulk>
    @csrf
    <div class="bulk-bar" data-bulk-bar hidden>
        <b data-bulk-count>0 selected</b>
        @if ($delhivery)<button name="action" value="book" class="btn small">🚚 Book with Delhivery</button>@endif
        <button name="action" value="in_production" class="btn small ghost">Mark being made</button>
        <button name="action" value="shipped" class="btn small ghost">Mark shipped</button>
        <button name="action" value="delivered" class="btn small ghost" data-confirm="Mark the ticked orders delivered? COD orders are marked paid.">Mark delivered</button>
        <button name="action" value="delete" class="btn small danger" data-confirm="Delete the ticked CANCELLED orders for good, with the customer's photos? Orders that aren't cancelled are skipped. This can't be undone.">🗑 Delete cancelled</button>
    </div>

    <div class="ol-list">
        <div class="ol-row ol-headrow">
            <label class="ol-check"><input type="checkbox" data-check-all aria-label="Select all"></label>
            <span>Order</span><span>Customer</span><span>Items</span><span>Total</span><span>Status</span>@if ($delhivery)<span>Delhivery</span>@endif
        </div>
        @forelse ($orders as $o)
            <div class="ol-row {{ $o->shipment_error && ! $o->tracking_number ? 'has-error' : '' }}">
                <label class="ol-check"><input type="checkbox" name="ids[]" value="{{ $o->id }}" data-check></label>
                <a href="{{ route('admin.orders.show', $o) }}" class="ol-no"><b>{{ $o->number }}</b><small>{{ $o->created_at->format('d M, H:i') }} · {{ $o->created_at->diffForHumans(null, true) }}</small></a>
                <div class="ol-cust"><b>{{ $o->name }}</b><small>{{ $o->phone }} · {{ $o->city }}</small></div>
                <div class="ol-items">
                    @foreach ($o->items->take(3) as $it)
                        <div class="oi-mini">
                            <span class="oi-thumbs">
                                @if ($it->uploads->isNotEmpty())
                                    @foreach ($it->uploads->take(3) as $u)<a href="{{ $u->fullUrl() }}" target="_blank" rel="noopener"><img src="{{ $u->thumb_path ? $u->thumbUrl() : $u->fullUrl() }}" alt="" loading="lazy"></a>@endforeach
                                    @if ($it->uploads->count() > 3)<em>+{{ $it->uploads->count() - 3 }}</em>@endif
                                @else
                                    <img src="{{ $it->product?->thumbUrl() ?? asset('images/placeholder.svg') }}" alt="" loading="lazy" class="prod">
                                @endif
                            </span>
                            <small>{{ $it->qty }} × {{ \Illuminate\Support\Str::limit($it->name, 30) }}</small>
                        </div>
                    @endforeach
                    @if ($o->items_count > 3)<small class="muted">+{{ $o->items_count - 3 }} more</small>@endif
                </div>
                <div class="ol-total"><b>{{ money($o->total) }}</b><span class="badge {{ $o->payment_status === 'paid' ? 'ok' : ($o->payment_status === 'failed' ? 'err' : 'warn') }}">{{ $o->payment_method === 'cod' ? 'COD' : ucfirst($o->payment_method) }} · {{ $o->payment_status }}</span></div>
                <div><span class="badge s-{{ $o->status }}">{{ $o->statusLabel() }}</span></div>
                @if ($delhivery)
                    <div class="ol-dv">
                        @if ($o->tracking_number)
                            <a href="{{ $o->trackingUrl() }}" target="_blank" rel="noopener" class="awb">{{ $o->tracking_number }}</a>
                            <small>{{ $o->shipment_status ?: 'Booked' }}</small>
                        @elseif ($o->shipment_error)
                            <span class="badge err" title="{{ $o->shipment_error }}">Not booked</span>
                            <small class="err-txt">{{ \Illuminate\Support\Str::limit($o->shipment_error, 60) }}</small>
                        @elseif (! $o->woo_id && in_array($o->status, ['confirmed', 'in_production'], true))
                            <span class="badge">To book</span>
                        @else
                            <small class="muted">—</small>
                        @endif
                    </div>
                @endif
            </div>
        @empty
            <div class="ol-empty muted">No orders found.@unless (request()->query()) <a href="{{ route('admin.import') }}">Import past orders from WordPress →</a>@endunless</div>
        @endforelse
    </div>
</form>
{{ $orders->links('admin.pagination') }}

@if ($delhivery)
<section class="card narrow">
    <h2>Delhivery pickup</h2>
    <p class="muted small">{{ $awaitingPickup }} {{ \Illuminate\Support\Str::plural('parcel', $awaitingPickup) }} booked and waiting to be picked up from <b>{{ \App\Services\Delhivery::pickupName() ?: 'your pickup location' }}</b>.</p>
    <form method="post" action="{{ route('admin.orders.pickup') }}" class="row3">
        @csrf
        <label>Date <input type="date" name="pickup_date" value="{{ now()->hour >= 14 ? now()->addDay()->toDateString() : now()->toDateString() }}" min="{{ now()->toDateString() }}"></label>
        <label>Time <input type="time" name="pickup_time" value="15:00"></label>
        <label>Parcels <input type="number" name="count" min="1" value="{{ max(1, $awaitingPickup) }}"></label>
        <button class="btn">Request pickup</button>
    </form>
</section>
@endif
@endsection
