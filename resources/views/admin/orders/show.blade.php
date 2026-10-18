@extends('admin.layout')
@section('title', 'Order '.$order->number)
@section('actions')
    @if ($order->items->sum(fn ($i) => $i->uploads->count()))
        <a class="btn" href="{{ route('admin.orders.uploads', $order) }}">Download all photos (ZIP)</a>
    @endif
@endsection
@section('content')
@php $wa = '91'.preg_replace('/\D/', '', $order->phone); @endphp
<div class="grid-main">
<div>
    <section class="card">
        <h2>Items</h2>
        @foreach ($order->items as $idx => $item)
            <div class="order-item">
                <div class="oi-head">
                    <div>
                        <strong>{{ $idx + 1 }}. {{ $item->name }}</strong>
                        @if ($item->options)<br><small class="muted">{{ collect($item->options)->map(fn ($v, $k) => "$k: $v")->implode(', ') }}</small>@endif
                        @if ($item->sku)<br><small class="muted">SKU {{ $item->sku }}</small>@endif
                    </div>
                    <div class="right">{{ $item->qty }} × {{ money($item->unit_price) }}<br><strong>{{ money($item->line_total) }}</strong></div>
                </div>
                @foreach ($item->customization ?? [] as $c)
                    <div class="oi-custom">
                        <span class="muted">{{ $c['label'] }}:</span>
                        @if (($c['type'] ?? '') === 'images')
                            <div class="uploads">
                                @foreach ($item->uploads->whereIn('token', $c['files'] ?? []) as $u)
                                    <a href="{{ $u->fullUrl() }}" target="_blank" rel="noopener" title="{{ $u->original_name }} {{ $u->width ? '('.$u->width.'×'.$u->height.')' : '' }}">
                                        <img src="{{ $u->thumb_path ? $u->thumbUrl() : $u->fullUrl() }}" alt="{{ $u->original_name }}" loading="lazy">
                                        @if ($u->width && min($u->width, $u->height) < 800)<span class="badge warn">low res</span>@endif
                                    </a>
                                @endforeach
                            </div>
                        @elseif (($c['type'] ?? '') === 'color')
                            <span class="swatch" style="background: {{ $c['value'] }}"></span> {{ $c['value'] }}
                        @else
                            <strong class="pre">{{ $c['value'] }}</strong>
                        @endif
                    </div>
                @endforeach
            </div>
        @endforeach
        <table class="tbl totals">
            <tr><td>Subtotal</td><td class="right">{{ money($order->subtotal) }}</td></tr>
            @if ((float) $order->discount)<tr><td>Discount {{ $order->coupon_code ? '('.$order->coupon_code.')' : '' }}</td><td class="right">−{{ money($order->discount) }}</td></tr>@endif
            <tr><td>Shipping</td><td class="right">{{ money($order->shipping) }}</td></tr>
            @if ((float) $order->cod_fee)<tr><td>COD fee</td><td class="right">{{ money($order->cod_fee) }}</td></tr>@endif
            <tr><th>Total</th><th class="right">{{ money($order->total) }}</th></tr>
        </table>
    </section>
</div>
<aside>
    <section class="card">
        <h2>Update</h2>
        <form method="post" action="{{ route('admin.orders.update', $order) }}">
            @csrf @method('PUT')
            <label>Status
                <select name="status">@foreach (\App\Models\Order::STATUSES as $k => $l)<option value="{{ $k }}" @selected($order->status === $k)>{{ $l }}</option>@endforeach</select>
            </label>
            <label>Payment
                <select name="payment_status">@foreach (['unpaid', 'paid', 'failed', 'refunded'] as $p)<option value="{{ $p }}" @selected($order->payment_status === $p)>{{ ucfirst($p) }}</option>@endforeach</select>
            </label>
            <label>Courier <input name="courier" value="{{ $order->courier }}" placeholder="Delhivery, DTDC…"></label>
            <label>Tracking number <input name="tracking_number" value="{{ $order->tracking_number }}"></label>
            <label>Notes <textarea name="notes" rows="3">{{ $order->notes }}</textarea></label>
            <button class="btn full">Save</button>
        </form>
    </section>
    <section class="card">
        <h2>Customer</h2>
        <p><strong>{{ $order->name }}</strong><br>
            <a href="tel:{{ $order->phone }}">{{ $order->phone }}</a> · <a href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener">WhatsApp</a><br>
            <a href="mailto:{{ $order->email }}">{{ $order->email }}</a></p>
        <p>{{ $order->address_line1 }}@if ($order->address_line2)<br>{{ $order->address_line2 }}@endif<br>{{ $order->city }}, {{ $order->state }} {{ $order->pincode }}</p>
        @if ($order->user)<p class="muted">Account: {{ $order->user->email }}</p>@endif
    </section>
    <section class="card">
        <h2>Payment</h2>
        <p>{{ strtoupper($order->payment_method) }} · <span class="badge {{ $order->payment_status === 'paid' ? 'ok' : '' }}">{{ $order->payment_status }}</span></p>
        @if ($order->razorpay_order_id)<p class="muted small">Razorpay order {{ $order->razorpay_order_id }}@if ($order->razorpay_payment_id)<br>Payment {{ $order->razorpay_payment_id }}@endif</p>@endif
        <p class="muted small">Placed {{ $order->created_at->format('d M Y, H:i') }}</p>
    </section>
    @if ($order->utm)
        <section class="card">
            <h2>Came from</h2>
            <table class="tbl compact">@foreach ($order->utm as $k => $v)<tr><td>{{ $k }}</td><td>{{ $v }}</td></tr>@endforeach</table>
        </section>
    @endif
</aside>
</div>
@endsection
