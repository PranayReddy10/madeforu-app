<section class="panel order-detail">
    <div class="order-meta">
        <span>Order <b>{{ $order->number }}</b></span>
        <span>{{ $order->created_at->format('j M Y') }}</span>
        <span class="status s-{{ $order->status }}">{{ $order->statusLabel() }}</span>
    </div>
    @php $steps = ['confirmed', 'in_production', 'shipped', 'delivered']; $at = array_search($order->status, $steps); @endphp
    @if($order->status !== 'cancelled')
        <ol class="progress">
            @foreach($steps as $i => $s)<li class="{{ $at !== false && $i <= $at ? 'done' : '' }}">{{ \App\Models\Order::STATUSES[$s] }}</li>@endforeach
        </ol>
    @endif
    @if($order->tracking_number)<p>Shipped with {{ $order->courier ?: 'our courier' }} · tracking <b>{{ $order->tracking_number }}</b></p>@endif
    @foreach($order->items as $item)
        <div class="mini-line">
            <span>{{ $item->name }} <small class="muted">× {{ $item->qty }}@if($item->options) · {{ implode(' / ', $item->options) }}@endif</small>
                @foreach($item->customization ?? [] as $c)
                    <small class="block muted">{{ $c['label'] }}: {{ $c['type'] === 'images' ? count($c['files'] ?? []).' photo(s)' : $c['value'] }}</small>
                @endforeach
            </span>
            <b>{{ money($item->line_total) }}</b>
        </div>
    @endforeach
    <dl class="totals">
        <div><dt>Subtotal</dt><dd>{{ money($order->subtotal) }}</dd></div>
        @if($order->discount > 0)<div class="good"><dt>Discount</dt><dd>−{{ money($order->discount) }}</dd></div>@endif
        <div><dt>Shipping</dt><dd>{{ $order->shipping > 0 ? money($order->shipping) : 'Free' }}</dd></div>
        @if($order->cod_fee > 0)<div><dt>COD fee</dt><dd>{{ money($order->cod_fee) }}</dd></div>@endif
        <div class="grand"><dt>Total</dt><dd>{{ money($order->total) }}</dd></div>
    </dl>
    <p class="small muted">Delivering to {{ $order->name }}, {{ $order->address_line1 }}@if($order->address_line2), {{ $order->address_line2 }}@endif, {{ $order->city }}, {{ $order->state }} {{ $order->pincode }}</p>
</section>
