<!doctype html>
<html><body style="margin:0;background:#fffaf5;font-family:Arial,Helvetica,sans-serif;color:#2b1d2e">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;padding:24px">
<tr><td>
    <h1 style="font-size:22px;margin:0 0 8px">{{ $forAdmin ? 'New order' : 'Thank you for your order!' }}</h1>
    <p style="margin:0 0 16px">Order <b>{{ $order->number }}</b> · {{ $order->created_at->format('j M Y, g:i a') }} · {{ strtoupper($order->payment_method) }} ({{ $order->payment_status }})</p>
    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse;font-size:14px">
    @foreach($order->items as $item)
        <tr style="border-bottom:1px solid #eee"><td>{{ $item->name }} × {{ $item->qty }}
            @if($item->options)<br><small>{{ implode(' / ', $item->options) }}</small>@endif
            @foreach($item->customization ?? [] as $c)<br><small>{{ $c['label'] }}: {{ $c['type'] === 'images' ? count($c['files'] ?? []).' photo(s)' : $c['value'] }}</small>@endforeach
        </td><td align="right">{{ money($item->line_total) }}</td></tr>
    @endforeach
        <tr><td>Shipping</td><td align="right">{{ $order->shipping > 0 ? money($order->shipping) : 'Free' }}</td></tr>
        @if($order->discount > 0)<tr><td>Discount</td><td align="right">−{{ money($order->discount) }}</td></tr>@endif
        @if($order->cod_fee > 0)<tr><td>Cash on delivery charges</td><td align="right">{{ money($order->cod_fee) }}</td></tr>@endif
        <tr><td><b>Total</b></td><td align="right"><b>{{ money($order->total) }}</b></td></tr>
    </table>
    <p style="font-size:14px">Delivering to: {{ $order->name }}, {{ $order->address_line1 }} {{ $order->address_line2 }}, {{ $order->city }}, {{ $order->state }} {{ $order->pincode }} · {{ $order->phone }}</p>
    @if($forAdmin)
        <p><a href="{{ route('admin.orders.show', $order) }}" style="background:#f04577;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Open in admin</a></p>
    @else
        <p><a href="{{ route('track', ['number' => $order->number, 'phone' => $order->phone]) }}" style="background:#f04577;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Track your order</a></p>
        @if($order->invoice_number)<p style="font-size:13px">Your invoice {{ $order->invoice_number }} is attached. <a href="{{ \App\Services\Invoice::publicUrl($order) }}">Download it here</a>.</p>@endif
        <p style="font-size:13px;color:#7a6b7d">We'll start making your gift now. If a photo needs adjusting we'll contact you before printing.</p>
    @endif
</td></tr></table>
</td></tr></table>
</body></html>
