@extends('admin.layout')
@section('title', 'Order '.$order->number)
@section('actions')
    <a class="btn ghost" href="{{ route('admin.orders.production', $order) }}" target="_blank" rel="noopener">🖨 Production sheet</a>
    @if ($order->items->sum(fn ($i) => $i->uploads->count()))
        <a class="btn" href="{{ route('admin.orders.uploads', $order) }}">⬇ Download all photos (ZIP)</a>
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
                @php $row = $sheet[$idx]; @endphp
                @forelse ($row['parts'] as $part)
                    <div class="oi-custom">
                        <span class="muted">{{ $part['label'] }}</span>
                        @if ($part['type'] === 'images')
                            <div class="uploads big">
                                @forelse ($part['photos'] as $p)
                                    @php $u = $p['upload']; $lowRes = $u->width && min($u->width, $u->height) < 800; @endphp
                                    <figure class="up">
                                        <a href="{{ $u->fullUrl() }}" target="_blank" rel="noopener" title="Open full size">
                                            <img src="{{ $u->thumb_path ? $u->thumbUrl() : $u->fullUrl() }}" alt="{{ $p['slot'] }}" loading="lazy">
                                        </a>
                                        <figcaption>
                                            <b>{{ $p['slot'] }}</b>
                                            <small class="muted">{{ $u->width ? $u->width.'×'.$u->height.' · ' : '' }}{{ number_format($u->size / 1048576, 1) }} MB</small>
                                            @if ($lowRes)<span class="badge warn">low res — may print blurry</span>@endif
                                            <a class="btn small ghost" href="{{ route('admin.orders.upload', [$order, $u]) }}">⬇ Download</a>
                                        </figcaption>
                                    </figure>
                                @empty
                                    <span class="err small">No photos were uploaded.</span>
                                @endforelse
                            </div>
                        @elseif ($part['type'] === 'color')
                            <div><span class="swatch" style="background: {{ $part['value'] }}"></span> {{ $part['value'] }}</div>
                        @else
                            <div class="print-text">
                                <strong class="pre">{{ $part['value'] !== '' ? $part['value'] : '—' }}</strong>
                                @if ($part['value'] !== '')<button type="button" class="btn small ghost" data-copy="{{ $part['value'] }}">Copy</button>@endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="muted small">No personalisation on this item.</p>
                @endforelse
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
        <h2>Invoice</h2>
        @if ($order->invoice_number)
            <p><strong>{{ $order->invoice_number }}</strong> <span class="muted small">{{ $order->invoiced_at?->format('d M Y') }}</span></p>
            <div class="row-actions"><a class="btn small" href="{{ route('admin.orders.invoice', $order) }}" target="_blank" rel="noopener">Open invoice PDF</a></div>
        @else
            <p class="muted small">The invoice number is given when the order is confirmed (COD placed or payment received).</p>
        @endif
    </section>
    <section class="card">
        <h2>WhatsApp</h2>
        @php $waTo = \App\Services\WhatsApp::number($order->phone); @endphp
        <p class="muted small">Opens WhatsApp with the message written — check it and press send.</p>
        <div class="wa-list">
            @foreach (\App\Services\OrderNotifier::manualMessages($order) as $label => $text)
                <a class="btn small ghost" href="https://wa.me/{{ $waTo }}?text={{ rawurlencode($text) }}" target="_blank" rel="noopener">{{ $label }}</a>
            @endforeach
        </div>
        @if (\App\Services\WhatsApp::enabled())
            <form method="post" action="{{ route('admin.orders.whatsapp', $order) }}" class="row-actions">
                @csrf
                <select name="event"><option value="confirmed">Order confirmed</option>@if ($order->tracking_number)<option value="shipped">Shipped</option>@endif<option value="delivered">Delivered</option></select>
                <button class="btn small">Send automatic message</button>
            </form>
        @endif
        @php $logs = \App\Models\NotificationLog::where('order_id', $order->id)->latest('id')->take(10)->get(); @endphp
        @if ($logs->isNotEmpty())
            <ul class="wa-log">
                @foreach ($logs as $log)
                    <li><span class="badge {{ $log->status === 'sent' ? 'ok' : '' }}">{{ $log->status }}</span> {{ $log->event }} → {{ $log->to }} <span class="muted small">{{ $log->created_at->format('d M, H:i') }}</span>@if ($log->error)<br><span class="err small">{{ $log->error }}</span>@endif</li>
                @endforeach
            </ul>
        @endif
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
