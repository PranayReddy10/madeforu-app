<!doctype html>
<html>
<head>
<meta charset="utf-8">
<title>Invoice {{ $order->invoice_number }}</title>
<style>
    @page { margin: 28px 32px; }
    body { font-family: 'DejaVu Sans', sans-serif; font-size: 10px; color: #1c2844; }
    h1 { font-size: 16px; margin: 0; color: #f04577; letter-spacing: 1px; }
    table { width: 100%; border-collapse: collapse; }
    .head td { vertical-align: top; }
    .muted { color: #6b7390; }
    .box { border: 1px solid #e6dbe2; border-radius: 6px; padding: 8px 10px; }
    .items th { background: #fdeef3; color: #1c2844; font-size: 9px; text-transform: uppercase; text-align: left; padding: 6px 5px; border-bottom: 1px solid #f2c4d3; }
    .items td { padding: 6px 5px; border-bottom: 1px solid #f1e6eb; vertical-align: top; }
    .r { text-align: right; }
    .c { text-align: center; }
    .totals td { padding: 3px 5px; }
    .grand td { font-size: 12px; font-weight: bold; border-top: 2px solid #1c2844; padding-top: 6px; }
    .small { font-size: 8.5px; }
</style>
</head>
<body>
<table class="head">
    <tr>
        <td style="width:55%">
            <img src="{{ $logo }}" style="height:46px" alt="">
            <p style="margin:6px 0 0"><b>{{ setting('invoice.legal_name') }}</b><br>
                {!! nl2br(e(setting('invoice.address'))) !!}<br>
                @if($b['gst'])GSTIN: <b>{{ setting('invoice.gstin') }}</b><br>@endif
                {{ config('store.email') }}@if(config('store.phone')) · {{ config('store.phone') }}@endif</p>
        </td>
        <td class="r">
            <h1>{{ $b['gst'] ? 'TAX INVOICE' : 'INVOICE' }}</h1>
            <p style="margin:6px 0 0">Invoice no. <b>{{ $order->invoice_number }}</b><br>
                Invoice date: {{ ($order->invoiced_at ?? $order->created_at)->format('d M Y') }}<br>
                Order no. {{ $order->number }} · {{ $order->created_at->format('d M Y') }}<br>
                Payment: {{ $order->payment_method === 'cod' ? 'Cash on delivery' : ucfirst($order->payment_method) }} ({{ $order->payment_status }})</p>
        </td>
    </tr>
</table>

<table style="margin-top:12px">
    <tr>
        <td style="width:50%;padding-right:6px" class="box">
            <span class="muted small">BILL TO / SHIP TO</span><br>
            <b>{{ $order->name }}</b><br>
            {{ $order->address_line1 }}@if($order->address_line2), {{ $order->address_line2 }}@endif<br>
            {{ $order->city }}, {{ $order->state }} {{ $order->pincode }}<br>
            {{ $order->phone }} · {{ $order->email }}
        </td>
        <td style="width:50%;padding-left:6px" class="box">
            <span class="muted small">PLACE OF SUPPLY</span><br>
            <b>{{ $order->state }}</b><br>
            @if($b['gst'])<span class="small">{{ $b['intra'] ? 'Intra-state supply: CGST + SGST' : 'Inter-state supply: IGST' }}</span><br>@endif
            @if($order->tracking_number)<span class="small">Shipped via {{ $order->courier }} · AWB {{ $order->tracking_number }}</span>@endif
        </td>
    </tr>
</table>

<table class="items" style="margin-top:12px">
    <thead><tr>
        <th style="width:4%">#</th><th style="width:36%">Item</th>@if($b['gst'])<th>HSN</th>@endif<th class="c">Qty</th><th class="r">Rate</th>
        @if($b['gst'])<th class="r">Taxable</th><th class="r">GST</th>@endif<th class="r">Amount</th>
    </tr></thead>
    <tbody>
    @foreach($b['lines'] as $i => $l)
        <tr>
            <td>{{ $i + 1 }}</td>
            <td>{{ $l['name'] }}@if($l['discount'] > 0)<br><span class="muted small">Discount −₹{{ number_format($l['discount'], 2) }}</span>@endif</td>
            @if($b['gst'])<td>{{ $l['hsn'] }}</td>@endif
            <td class="c">{{ $l['qty'] }}</td>
            <td class="r">₹{{ number_format($l['unit'], 2) }}</td>
            @if($b['gst'])<td class="r">₹{{ number_format($l['taxable'], 2) }}</td><td class="r">{{ rtrim(rtrim(number_format($l['rate'], 2), '0'), '.') }}%<br>₹{{ number_format($l['tax'], 2) }}</td>@endif
            <td class="r">₹{{ number_format($l['total'], 2) }}</td>
        </tr>
    @endforeach
    @foreach($b['charges'] as $c)
        <tr>
            <td></td><td>{{ $c['name'] }}</td>@if($b['gst'])<td></td>@endif<td></td><td></td>
            @if($b['gst'])<td class="r">₹{{ number_format($c['taxable'], 2) }}</td><td class="r">{{ rtrim(rtrim(number_format($c['rate'], 2), '0'), '.') }}%<br>₹{{ number_format($c['tax'], 2) }}</td>@endif
            <td class="r">₹{{ number_format($c['total'], 2) }}</td>
        </tr>
    @endforeach
    </tbody>
</table>

<table style="margin-top:10px">
    <tr>
        <td style="width:55%;vertical-align:top" class="small">
            <b>Amount in words:</b> {{ \App\Services\Invoice::inWords($b['total']) }}<br><br>
            @if($order->coupon_code)Coupon used: {{ $order->coupon_code }}<br>@endif
            {{ setting('invoice.footer') }}
        </td>
        <td style="vertical-align:top">
            <table class="totals">
                @if($b['gst'])
                    <tr><td>Taxable value</td><td class="r">₹{{ number_format($b['taxable'], 2) }}</td></tr>
                    @foreach($b['taxes'] as $rate => $tax)
                        @if($b['intra'])
                            <tr><td>CGST @ {{ rtrim(rtrim(number_format($rate / 2, 2), '0'), '.') }}%</td><td class="r">₹{{ number_format($tax / 2, 2) }}</td></tr>
                            <tr><td>SGST @ {{ rtrim(rtrim(number_format($rate / 2, 2), '0'), '.') }}%</td><td class="r">₹{{ number_format($tax - round($tax / 2, 2), 2) }}</td></tr>
                        @else
                            <tr><td>IGST @ {{ rtrim(rtrim(number_format((float) $rate, 2), '0'), '.') }}%</td><td class="r">₹{{ number_format($tax, 2) }}</td></tr>
                        @endif
                    @endforeach
                @else
                    <tr><td>Subtotal</td><td class="r">₹{{ number_format($order->subtotal, 2) }}</td></tr>
                    @if($order->discount > 0)<tr><td>Discount</td><td class="r">−₹{{ number_format($order->discount, 2) }}</td></tr>@endif
                @endif
                <tr class="grand"><td>Total</td><td class="r">₹{{ number_format($b['total'], 2) }}</td></tr>
                @if($order->payment_status === 'paid')<tr><td class="muted">Paid</td><td class="r muted">₹{{ number_format($b['total'], 2) }}</td></tr>
                @elseif($order->payment_method === 'cod')<tr><td class="muted">To pay on delivery</td><td class="r muted">₹{{ number_format($b['total'], 2) }}</td></tr>@endif
            </table>
        </td>
    </tr>
</table>

<p class="small muted" style="margin-top:24px;text-align:center">This is a computer-generated invoice and does not need a signature. · {{ config('app.url') }}</p>
</body>
</html>
