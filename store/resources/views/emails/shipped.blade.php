<!doctype html>
<html><body style="margin:0;background:#fffafc;font-family:Arial,Helvetica,sans-serif;color:#1c2844">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;padding:24px">
<tr><td>
    <h1 style="font-size:22px;margin:0 0 8px">Your order is on its way 🚚</h1>
    <p>Hi {{ \Illuminate\Support\Str::before($order->name, ' ') }}, your order <b>{{ $order->number }}</b> has been shipped with {{ $order->courier ?: 'Delhivery' }}.</p>
    <p>Tracking number: <b>{{ $order->tracking_number }}</b></p>
    @if($order->payment_method === 'cod' && $order->payment_status !== 'paid')
        <p>Please keep <b>{{ money($order->total) }}</b> ready to pay on delivery.</p>
    @endif
    <p><a href="{{ $order->trackingUrl() }}" style="background:#f04577;color:#fff;padding:10px 16px;border-radius:8px;text-decoration:none">Track your parcel</a></p>
    <p style="font-size:13px;color:#858ca3">Or see it on our site: <a href="{{ route('track', ['number' => $order->number, 'phone' => $order->phone]) }}">{{ route('track') }}</a></p>
</td></tr></table>
</td></tr></table>
</body></html>
