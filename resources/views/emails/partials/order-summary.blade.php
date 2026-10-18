<table width="100%" cellpadding="6" cellspacing="0" role="presentation" style="border-collapse:collapse;font-size:14px;margin:8px 0">
@foreach($order->items as $item)
    <tr style="border-bottom:1px solid #f2e6eb"><td style="padding:8px 0">{{ $item->name }} × {{ $item->qty }}
        @if($item->options)<br><small style="color:#858ca3">{{ implode(' / ', $item->options) }}</small>@endif
        @foreach($item->customization ?? [] as $c)<br><small style="color:#858ca3">{{ $c['label'] }}: {{ ($c['type'] ?? '') === 'images' ? count($c['files'] ?? []).' photo(s)' : \Illuminate\Support\Str::limit($c['value'] ?? '', 80) }}</small>@endforeach
    </td><td align="right" style="padding:8px 0;white-space:nowrap">{{ money($item->line_total) }}</td></tr>
@endforeach
    <tr><td style="padding:4px 0">Shipping</td><td align="right">{{ $order->shipping > 0 ? money($order->shipping) : 'Free' }}</td></tr>
    @if($order->discount > 0)<tr><td style="padding:4px 0">Discount @if($order->coupon_code)({{ $order->coupon_code }})@endif</td><td align="right">−{{ money($order->discount) }}</td></tr>@endif
    @if($order->cod_fee > 0)<tr><td style="padding:4px 0">Cash on delivery charges</td><td align="right">{{ money($order->cod_fee) }}</td></tr>@endif
    <tr><td style="padding:8px 0;border-top:2px solid #1c2844"><b>Total</b></td><td align="right" style="border-top:2px solid #1c2844"><b>{{ money($order->total) }}</b></td></tr>
</table>
<p style="font-size:13px;color:#4a5470;margin:6px 0 0">{{ $order->payment_method === 'cod' ? ($order->payment_status === 'paid' ? 'Cash on delivery · paid' : 'Cash on delivery · pay '.money($order->total).' when it arrives') : 'Paid online ('.ucfirst($order->payment_method).')' }}<br>
Delivering to {{ $order->name }}, {{ $order->address_line1 }}@if($order->address_line2), {{ $order->address_line2 }}@endif, {{ $order->city }}, {{ $order->state }} {{ $order->pincode }}</p>
