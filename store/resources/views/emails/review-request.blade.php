<!doctype html>
<html><body style="margin:0;background:#fffafc;font-family:Arial,Helvetica,sans-serif;color:#1c2844">
<table width="100%" cellpadding="0" cellspacing="0"><tr><td align="center" style="padding:24px">
<table width="600" cellpadding="0" cellspacing="0" style="max-width:600px;background:#fff;border-radius:12px;padding:24px">
<tr><td>
    <img src="{{ asset('images/logo.png') }}" alt="{{ config('store.name') }}" height="44" style="height:44px">
    <h1 style="font-size:22px;margin:16px 0 8px">Hi {{ \Illuminate\Support\Str::before($order->name, ' ') }}, how was your gift?</h1>
    <p>We hope {{ $order->items->count() > 1 ? 'everything' : 'it' }} from order <b>{{ $order->number }}</b> made someone smile. Would you share a quick review? A photo of the gift means the world to a small shop like ours.</p>
    <table width="100%" cellpadding="6" cellspacing="0">
    @foreach($order->items->pluck('product')->filter()->unique('id') as $p)
        <tr><td width="64"><img src="{{ $p->mainImageUrl() }}" alt="" width="56" height="56" style="border-radius:8px"></td><td><b>{{ $p->name }}</b><br><span style="color:#ffb547;font-size:20px">★★★★★</span></td></tr>
    @endforeach
    </table>
    <p style="text-align:center;margin:24px 0"><a href="{{ $link }}" style="background:#f04577;color:#fff;padding:14px 26px;border-radius:999px;text-decoration:none;font-weight:bold">Write a review</a></p>
    <p style="font-size:13px;color:#858ca3;text-align:center">Takes less than a minute. Thank you for shopping with {{ config('store.name') }}!</p>
</td></tr></table>
</td></tr></table>
</body></html>
