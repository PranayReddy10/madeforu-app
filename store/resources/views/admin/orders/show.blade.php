@extends('admin.layout')
@section('title', 'Order '.$order->number)
@section('content')
@php
    $wa = \App\Services\WhatsApp::number($order->phone) ?: '91'.preg_replace('/\D/', '', $order->phone);
    $flow = ['pending' => 'Placed', 'confirmed' => 'Confirmed', 'in_production' => 'Being made', 'shipped' => 'Shipped', 'delivered' => 'Delivered'];
    $keys = array_keys($flow);
    $at = array_search($order->status, $keys, true);
    $stopped = in_array($order->status, ['cancelled', 'returned'], true);
    $next = ['pending' => 'confirmed', 'confirmed' => 'in_production', 'in_production' => 'shipped', 'shipped' => 'delivered'][$order->status] ?? null;
    $photos = $order->items->sum(fn ($i) => $i->uploads->count());
    $orderCount = \App\Models\Order::where('phone', $order->phone)->count();
    $paid = $order->payment_status === 'paid';
@endphp

<section class="card od-head">
    <div class="od-top">
        <div>
            <div class="od-no">{{ $order->number }} <button type="button" class="link" data-copy="{{ $order->number }}" title="Copy">⧉</button></div>
            <div class="muted small">Placed {{ $order->created_at->format('D, d M Y \a\t H:i') }} · {{ $order->created_at->diffForHumans() }}@if ($order->woo_id) · imported from WordPress @endif</div>
            <div class="od-badges">
                <span class="badge s-{{ $order->status }}">{{ $order->statusLabel() }}</span>
                <span class="badge {{ $paid ? 'ok' : ($order->payment_status === 'failed' ? 'err' : 'warn') }}">{{ $order->payment_method === 'cod' ? 'COD' : ucfirst($order->payment_method) }} · {{ $order->payment_status }}</span>
                @if ($photos)<span class="badge">📷 {{ $photos }} {{ \Illuminate\Support\Str::plural('photo', $photos) }}</span>@endif
                @if ($order->tracking_number)<span class="badge">🚚 {{ $order->courier ?: 'Courier' }} {{ $order->tracking_number }}</span>@endif
            </div>
        </div>
        <div class="od-total"><span class="muted small">Total</span><b>{{ money($order->total) }}</b><span class="muted small">{{ $order->items->sum('qty') }} {{ \Illuminate\Support\Str::plural('item', $order->items->sum('qty')) }}</span></div>
    </div>

    @if ($stopped)
        <p class="od-stopped">This order is <b>{{ strtolower($order->statusLabel()) }}</b>.</p>
    @else
        <ol class="od-steps">
            @foreach ($flow as $k => $label)
                @php $i = array_search($k, $keys, true); @endphp
                <li class="{{ $i < $at ? 'done' : ($i === $at ? 'now' : '') }}"><span>{{ $i < $at ? '✓' : $i + 1 }}</span>{{ $label }}</li>
            @endforeach
        </ol>
    @endif

    <div class="od-actions">
        @if ($next && ! $stopped)
            <form method="post" action="{{ route('admin.orders.update', $order) }}">
                @csrf @method('PUT')
                <input type="hidden" name="status" value="{{ $next }}">
                <input type="hidden" name="payment_status" value="{{ $next === 'delivered' && $order->payment_method === 'cod' ? 'paid' : $order->payment_status }}">
                <input type="hidden" name="courier" value="{{ $order->courier }}"><input type="hidden" name="tracking_number" value="{{ $order->tracking_number }}">
                <input type="hidden" name="notes" value="{{ $order->notes }}">
                <button class="btn">Mark as {{ \App\Models\Order::STATUSES[$next] }}{{ $next === 'delivered' && $order->payment_method === 'cod' && ! $paid ? ' (COD collected)' : '' }} →</button>
            </form>
        @endif
        <a class="btn ghost" href="{{ route('admin.orders.production', $order) }}" target="_blank" rel="noopener">🖨 Production sheet</a>
        @if ($photos)<a class="btn ghost" href="{{ route('admin.orders.uploads', $order) }}">⬇ Photos (ZIP)</a>@endif
        @if ($order->invoice_number)<a class="btn ghost" href="{{ route('admin.orders.invoice', $order) }}" target="_blank" rel="noopener">🧾 Invoice</a>@endif
        @if ($order->tracking_number)<a class="btn ghost" href="{{ route('admin.orders.label', $order) }}" target="_blank" rel="noopener">🏷 Shipping label</a>@endif
        <a class="btn ghost" href="tel:{{ $order->phone }}">📞 Call</a>
        <a class="btn ghost wa-btn" href="https://wa.me/{{ $wa }}" target="_blank" rel="noopener">💬 WhatsApp</a>
    </div>
</section>

<div class="grid-main">
<div>
    <div class="od-people">
        <section class="card">
            <h2>Customer</h2>
            <p class="od-name">{{ $order->name }} @if ($orderCount > 1)<span class="badge ok">{{ $orderCount }} orders</span>@else<span class="badge">First order</span>@endif</p>
            <p class="od-lines">
                <a href="tel:{{ $order->phone }}">📞 {{ $order->phone }}</a>
                @if ($order->email)<a href="mailto:{{ $order->email }}">✉ {{ $order->email }}</a>@endif
                @if ($order->user)<span class="muted small">Has an account</span>@else<span class="muted small">Guest checkout</span>@endif
            </p>
        </section>
        <section class="card">
            <h2>Ship to <button type="button" class="link small" data-copy="{{ $order->name }}, {{ $order->address_line1 }}{{ $order->address_line2 ? ', '.$order->address_line2 : '' }}, {{ $order->city }}, {{ $order->state }} {{ $order->pincode }}, {{ $order->phone }}">Copy</button></h2>
            <p class="od-addr">{{ $order->address_line1 }}@if ($order->address_line2)<br>{{ $order->address_line2 }}@endif<br>{{ $order->city }}, {{ $order->state }} <b>{{ $order->pincode }}</b></p>
            <a class="link small" href="https://www.google.com/maps/search/?api=1&query={{ rawurlencode($order->address_line1.' '.$order->city.' '.$order->pincode) }}" target="_blank" rel="noopener">Open in Maps ↗</a>
            <details class="addr-edit" @if ($errors->hasAny(['name', 'phone', 'address_line1', 'city', 'state', 'pincode'])) open @endif>
                <summary class="link small">✎ Edit name, phone &amp; address</summary>
                <form method="post" action="{{ route('admin.orders.update', $order) }}">
                    @csrf @method('PUT')
                    @foreach (['status', 'payment_status', 'courier', 'tracking_number', 'notes'] as $keep)<input type="hidden" name="{{ $keep }}" value="{{ $order->$keep }}">@endforeach
                    <div class="row2">
                        <label>Name <input name="name" value="{{ old('name', $order->name) }}" required></label>
                        <label>Phone <input name="phone" value="{{ old('phone', $order->phone) }}" required></label>
                    </div>
                    <label>Address <input name="address_line1" value="{{ old('address_line1', $order->address_line1) }}" required></label>
                    <label>Landmark / area <input name="address_line2" value="{{ old('address_line2', $order->address_line2) }}"></label>
                    <div class="row3">
                        <label>City <input name="city" value="{{ old('city', $order->city) }}" required></label>
                        <label>State <input name="state" value="{{ old('state', $order->state) }}" required></label>
                        <label>PIN <input name="pincode" value="{{ old('pincode', $order->pincode) }}" required pattern="\d{6}"></label>
                    </div>
                    @if ($errors->any())<p class="err small">{{ $errors->first() }}</p>@endif
                    <button class="btn small">Save details</button>
                </form>
            </details>
        </section>
    </div>

    <section class="card">
        <h2>Items to make</h2>
        @foreach ($order->items as $idx => $item)
            <div class="order-item">
                <div class="oi-head">
                    <img class="oi-thumb" src="{{ $item->product?->thumbUrl() ?? asset('images/placeholder.svg') }}" alt="" loading="lazy">
                    <div class="oi-title">
                        <strong>{{ $item->name }}</strong>
                        @if ($item->options)<br><small class="muted">{{ collect($item->options)->map(fn ($v, $k) => "$k: $v")->implode(', ') }}</small>@endif
                        @if ($sku = $item->skuCode())<br><small class="muted">SKU <b>{{ $sku }}</b></small>@endif
                    </div>
                    <div class="right oi-price"><span class="qty-pill">× {{ $item->qty }}</span><small class="muted">{{ money($item->unit_price) }} each</small><strong>{{ money($item->line_total) }}</strong></div>
                </div>
                @php $row = $sheet[$idx]; @endphp
                <div class="oi-parts">
                @forelse ($row['parts'] as $part)
                    <div class="oi-custom {{ $part['type'] === 'images' ? 'is-photos' : 'is-text' }}">
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
                                @if (! empty($part['image']))<img src="{{ $part['image'] }}" alt="" class="opt-img">@endif
                                <strong class="pre">{{ $part['value'] !== '' ? $part['value'] : '—' }}</strong>
                                @if ($part['value'] !== '')<button type="button" class="btn small ghost" data-copy="{{ $part['value'] }}">Copy</button>@endif
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="muted small">No personalisation on this item.</p>
                @endforelse
                </div>
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
        <h2>Status &amp; notes</h2>
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
        <h2>Invoice</h2>
        @if ($order->invoice_number)
            <p><strong>{{ $order->invoice_number }}</strong> <span class="muted small">{{ $order->invoiced_at?->format('d M Y') }}</span></p>
            <div class="row-actions"><a class="btn small" href="{{ route('admin.orders.invoice', $order) }}" target="_blank" rel="noopener">Open invoice PDF</a></div>
        @else
            <p class="muted small">The invoice number is given when the order is confirmed (COD placed or payment received).</p>
        @endif
    </section>
    <section class="card">
        <h2>Messages</h2>
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
        @php $logs = \App\Models\NotificationLog::where('order_id', $order->id)->latest('id')->take(20)->get(); @endphp
        @if ($logs->isNotEmpty())<h3 class="small muted" style="margin:12px 0 0">Messages sent (email &amp; WhatsApp)</h3>@endif
        @if ($logs->isNotEmpty())
            <ul class="wa-log">
                @foreach ($logs as $log)
                    <li><span class="badge {{ $log->status === 'sent' ? 'ok' : '' }}">{{ $log->status }}</span> {{ $log->channel === 'email' ? '✉' : '💬' }} {{ str_replace('_', ' ', $log->event) }} → {{ $log->to }} <span class="muted small">{{ $log->created_at->format('d M, H:i') }}</span>@if ($log->error)<br><span class="err small">{{ $log->error }}</span>@endif</li>
                @endforeach
            </ul>
        @endif
    </section>

    <section class="card">
        <h2>Payment</h2>
        <table class="tbl compact">
            <tr><td>Method</td><td>{{ $order->payment_method === 'cod' ? 'Cash on delivery' : ucfirst($order->payment_method) }}</td></tr>
            <tr><td>Status</td><td><span class="badge {{ $paid ? 'ok' : '' }}">{{ $order->payment_status }}</span></td></tr>
            @if ($order->razorpay_payment_id)<tr><td>Razorpay</td><td class="small">{{ $order->razorpay_payment_id }}</td></tr>@endif
            @if ($order->cashfree_payment_id)<tr><td>Cashfree</td><td class="small">{{ $order->cashfree_payment_id }}</td></tr>@endif
        </table>
    </section>
    @if ($order->utm)
        <section class="card">
            <h2>Came from</h2>
            <table class="tbl compact">@foreach ($order->utm as $k => $v)<tr><td>{{ $k }}</td><td>{{ $v }}</td></tr>@endforeach</table>
        </section>
    @endif
    @if ($order->status === 'cancelled')
    <section class="card danger-zone">
        <h2>Delete order</h2>
        <p class="muted small">Removes this cancelled order for good, with the customer's uploaded photos.@if ($order->invoice_number) It had invoice {{ $order->invoice_number }} — keep a copy for your GST records first.@endif</p>
        <form method="post" action="{{ route('admin.orders.destroy', $order) }}">
            @csrf @method('DELETE')
            <button class="btn danger" data-confirm="Delete order {{ $order->number }} for good? This can't be undone.">🗑 Delete order</button>
        </form>
    </section>
    @endif
</aside>
</div>
@endsection
