@extends('emails.layout')
@section('preheader')Your cart is saved — finish your order in a minute@endsection
@section('body')
    <h1 style="font-size:22px;margin:0 0 8px">Hi{{ $cart->name ? ' '.\Illuminate\Support\Str::before($cart->name, ' ') : '' }}, your gift is waiting 💝</h1>
    <p style="margin:0 0 16px">You left {{ count($items) > 1 ? 'these' : 'this' }} in your cart{{ collect($cart->lines)->pluck('custom')->flatten()->filter()->isNotEmpty() ? ' — your photos are still saved, so it only takes a minute to finish' : '' }}.</p>
    <table width="100%" cellpadding="6" cellspacing="0" style="border-collapse:collapse">
    @foreach($items as $i)
        <tr style="border-bottom:1px solid #f2e6eb">
            <td width="72"><img src="{{ $i['product']->mainImageUrl() }}" alt="" width="64" height="64" style="border-radius:8px;object-fit:cover"></td>
            <td><b>{{ $i['product']->name }}</b>@if($i['variant'])<br><small style="color:#858ca3">{{ $i['variant']->label() }}</small>@endif<br><small>Qty {{ $i['qty'] }}</small></td>
            <td align="right"><b>{{ money(($i['variant'] ? $i['variant']->currentPrice() : $i['product']->currentPrice()) * $i['qty']) }}</b></td>
        </tr>
    @endforeach
    </table>
    @if($coupon)
        <p style="background:#ffe9f0;border-radius:10px;padding:12px;margin:16px 0;text-align:center">Use code <b style="font-size:18px;letter-spacing:1px">{{ $coupon }}</b> at checkout for a little extra off.</p>
    @endif
    <p style="text-align:center;margin:24px 0"><a href="{{ $cart->recoverUrl().($coupon ? '?coupon='.urlencode($coupon) : '') }}" style="background:#f04577;color:#fff;padding:14px 26px;border-radius:999px;text-decoration:none;font-weight:bold">Complete my order</a></p>
    <p style="font-size:13px;color:#858ca3;text-align:center">Free shipping over {{ money(setting('free_shipping_over')) }}@if(setting('cod_enabled')) · Cash on delivery available@endif</p>
@endsection
@section('footer')<br>Don't want these reminders? <a href="{{ route('cart.unsubscribe', $cart->token) }}" style="color:#a0a6b8">Unsubscribe</a>@endsection
