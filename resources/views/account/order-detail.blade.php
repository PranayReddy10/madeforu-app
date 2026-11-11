<section class="panel order-detail">
    <div class="order-meta">
        <span>Order <b>{{ $order->number }}</b></span>
        <span>{{ $order->created_at->format('j M Y') }}</span>
        <span class="status s-{{ $order->status }}">{{ $order->statusLabel() }}</span>
    </div>
    @include('partials.tracking', ['order' => $order])
    @foreach($order->items as $item)
        <div class="mini-line">
            <span>{{ $item->name }} <small class="muted">× {{ $item->qty }}@if($item->options) · {{ implode(' / ', $item->options) }}@endif @if($sku = $item->skuCode()) · SKU {{ $sku }}@endif</small>
                @foreach($item->customization ?? [] as $c)
                    <small class="block muted">{{ $c['label'] }}: {{ $c['type'] === 'images' ? count($c['files'] ?? []).' photo(s)' : $c['value'] }}</small>
                @endforeach
            </span>
            <b>{{ money($item->line_total) }}</b>
        </div>
    @endforeach
    <dl class="totals">
        <div><dt>Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
        @if($order->discount - $order->prepaid_discount > 0)<div class="good"><dt>Discount @if($order->coupon_code)({{ $order->coupon_code }})@endif</dt><dd>−{{ money($order->discount - $order->prepaid_discount) }}</dd></div>@endif
        @if($order->prepaid_discount > 0)<div class="good"><dt>Online payment discount</dt><dd>−{{ money($order->prepaid_discount) }}</dd></div>@endif
        <div><dt>Shipping</dt><dd>{{ $order->shipping > 0 ? money($order->shipping) : 'Free' }}</dd></div>
        @if($order->cod_fee > 0)<div><dt>Cash on delivery charges</dt><dd>{{ money($order->cod_fee) }}</dd></div>@endif
        @if($order->extra_charge > 0)<div><dt>{{ $order->extra_charge_label ?: 'Extra charges' }}</dt><dd>{{ money($order->extra_charge) }}</dd></div>@endif
        <div class="grand"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
    </dl>
    <p class="small muted">Delivering to {{ $order->name }}, {{ $order->address_line1 }}@if($order->address_line2), {{ $order->address_line2 }}@endif, {{ $order->city }}, {{ $order->state }} {{ $order->pincode }}</p>
</section>
