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
            @if ((float) $order->cod_fee)<tr><td>Cash on delivery charges</td><td class="right">{{ money($order->cod_fee) }}</td></tr>@endif
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
        <h2>Delhivery</h2>
        @if ($order->tracking_number)
            <p>AWB <strong>{{ $order->tracking_number }}</strong> · <a href="{{ $order->trackingUrl() }}" target="_blank" rel="noopener">track ↗</a></p>
            @if ($order->shipment_status)<p><span class="badge">{{ $order->shipment_status }}</span>@if ($order->tracked_at) <span class="muted small">checked {{ $order->tracked_at->diffForHumans() }}</span>@endif</p>@endif
            <div class="row-actions">
                <a class="btn small" href="{{ route('admin.orders.label', $order) }}" target="_blank" rel="noopener">Print label</a>
                <form method="post" action="{{ route('admin.orders.track', $order) }}">@csrf<button class="btn small ghost">Refresh tracking</button></form>
                @unless (in_array($order->status, ['shipped', 'delivered', 'returned'], true))
                    <form method="post" action="{{ route('admin.orders.cancel-shipment', $order) }}">@csrf<button class="btn small danger" data-confirm="Cancel this Delhivery shipment?">Cancel shipment</button></form>
                @endunless
            </div>
            @if ($order->shipment_scans)
                <details class="scans"><summary>Scan history ({{ count($order->shipment_scans) }})</summary>
                    <ol>@foreach (array_reverse($order->shipment_scans) as $sc)<li><strong>{{ $sc['status'] }}</strong> · {{ $sc['location'] }}<br><span class="muted small">{{ $sc['at'] ? \Illuminate\Support\Carbon::parse($sc['at'])->format('d M, H:i') : '' }} {{ $sc['note'] }}</span></li>@endforeach</ol>
                </details>
            @endif
        @elseif (\App\Services\Delhivery::enabled())
            @if ($order->shipment_error)<p class="err small">Last attempt failed: {{ $order->shipment_error }}</p>@endif
            @if (in_array($order->status, ['pending', 'cancelled'], true))
                <p class="muted small">Shipments are created once the order is confirmed (COD placed or payment received).</p>
            @else
                <form method="post" action="{{ route('admin.orders.ship', $order) }}">
                    @csrf
                    <label>Weight (grams) <input type="number" name="weight_grams" min="50" value="{{ $order->shippingWeight() }}"></label>
                    <button class="btn full">{{ $order->shipment_error ? 'Retry' : 'Create' }} Delhivery shipment</button>
                </form>
            @endif
        @else
            <p class="muted small">Add your Delhivery API token in <a href="{{ route('admin.settings') }}">Settings → Delhivery shipping</a> to book shipments from here.</p>
        @endif
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
        <p>{{ $order->payment_method === 'cod' ? 'Cash on delivery' : ucfirst($order->payment_method) }} · <span class="badge {{ $order->payment_status === 'paid' ? 'ok' : '' }}">{{ $order->payment_status }}</span></p>
        @if ($order->razorpay_order_id)<p class="muted small">Razorpay order {{ $order->razorpay_order_id }}@if ($order->razorpay_payment_id)<br>Payment {{ $order->razorpay_payment_id }}@endif</p>@endif
        @if ($order->cashfree_order_id)<p class="muted small">Cashfree order {{ $order->cashfree_order_id }}@if ($order->cashfree_payment_id)<br>Payment {{ $order->cashfree_payment_id }}@endif</p>@endif
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
