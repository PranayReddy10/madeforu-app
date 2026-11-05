@extends('admin.layout')
@section('title', 'Sales')
@section('actions')
    <a class="btn small ghost" href="{{ route('admin.sales.export', request()->query()) }}">⬇ Download for accounts (CSV)</a>
@endsection
@section('content')
@php
    $q = fn (array $more) => route('admin.sales', array_filter(array_merge(request()->only('p', 'from', 'to', 'method'), $more), fn ($v) => $v !== null && $v !== ''));
@endphp
<form method="get" class="card sales-range">
    <div class="range">
        @foreach (\App\Services\SalesReport::PERIODS as $k => $l)
            @if ($k !== 'custom')<a class="btn small {{ $r->period === $k ? '' : 'ghost' }}" href="{{ $q(['p' => $k, 'from' => null, 'to' => null]) }}">{{ $l }}</a>@endif
        @endforeach
    </div>
    <div class="range-custom">
        <input type="hidden" name="p" value="custom">
        @if ($method)<input type="hidden" name="method" value="{{ $method }}">@endif
        <label>From <input type="date" name="from" value="{{ $r->from->format('Y-m-d') }}"></label>
        <label>To <input type="date" name="to" value="{{ $r->to->format('Y-m-d') }}"></label>
        <button class="btn small ghost">Show</button>
    </div>
    <p class="muted small">{{ $r->from->format('j M Y') }} – {{ $r->to->format('j M Y') }} · an order counts as a sale on the day it is <b>delivered</b>.</p>
</form>

<div class="cards">
    <div class="card stat"><span>Sales (delivered)</span><strong>{{ money($s['sales']['value']) }}</strong><small>{{ $s['sales']['count'] }} {{ \Illuminate\Support\Str::plural('order', $s['sales']['count']) }}</small></div>
    <div class="card stat"><span>💳 Paid online</span><strong>{{ money($s['online']['value']) }}</strong><small>{{ $s['online']['count'] }} delivered · Razorpay / Cashfree</small></div>
    <div class="card stat"><span>💵 Cash on delivery</span><strong>{{ money($s['cod']['value']) }}</strong><small>{{ $s['cod']['count'] }} delivered · {{ money($s['cod_received']['value']) }} received in bank</small></div>
    <div class="card stat {{ $s['cod_due']['value'] > 0 ? 'stat-warn' : '' }}"><span>COD still to receive from Delhivery</span><strong>{{ money($s['cod_due']['value']) }}</strong><small>{{ $s['cod_due']['count'] }} delivered {{ \Illuminate\Support\Str::plural('order', $s['cod_due']['count']) }} · all dates</small></div>
</div>

<div class="grid2">
    <div class="card">
        <h2>On the way (not delivered yet)</h2>
        <table class="tbl compact">
            <tr><td>Paid online, being made or shipped</td><td class="right">{{ money($s['open_online']['value']) }}</td><td class="right muted small">{{ $s['open_online']['count'] }}</td></tr>
            <tr><td>Cash on delivery to collect</td><td class="right">{{ money($s['open_cod']['value']) }}</td><td class="right muted small">{{ $s['open_cod']['count'] }}</td></tr>
            <tr><td>Awaiting online payment <span class="muted small">(last 7 days)</span></td><td class="right">{{ money($s['awaiting_payment']['value']) }}</td><td class="right muted small">{{ $s['awaiting_payment']['count'] }}</td></tr>
        </table>
        <p class="muted small">Not counted as sales until delivered.</p>
    </div>
    <div class="card">
        <h2>Returns &amp; cancellations <small class="muted">in this period</small></h2>
        <table class="tbl compact">
            <tr><td>Returned to us (RTO)</td><td class="right">{{ money($s['returned']['value']) }}</td><td class="right muted small">{{ $s['returned']['count'] }}</td></tr>
            <tr><td>Cancelled</td><td class="right">{{ money($s['cancelled']['value']) }}</td><td class="right muted small">{{ $s['cancelled']['count'] }}</td></tr>
            <tr><td>Refunded</td><td class="right">{{ money($s['refunded']['value']) }}</td><td class="right muted small">{{ $s['refunded']['count'] }}</td></tr>
        </table>
        <h2 style="margin-top:14px">GST on sales</h2>
        <table class="tbl compact">
            <tr><td>Taxable value</td><td class="right">{{ money($gst['taxable']) }}</td></tr>
            <tr><td>GST</td><td class="right">{{ money($gst['gst']) }}</td></tr>
            <tr><th>Total</th><th class="right">{{ money($gst['total']) }}</th></tr>
        </table>
    </div>
</div>

<section class="card panel-sync">
    <div>
        <h2>Sale panel <small class="muted">sale.madeforu.co.in</small></h2>
        @if (! $panel['configured'])
            <p class="small">Not connected. In the sale panel open <b>Website sync</b>, create a key, then paste the address and key into
                <a href="{{ route('admin.settings') }}#sale-panel-sync">Settings → Sale panel sync</a>. Delivered orders are then added there automatically.</p>
        @else
            <p class="small">
                @if ($panel['enabled'])<span class="badge ok">On</span> Every order is added to the sale panel when it’s delivered.
                @else<span class="badge warn">Off</span> Connected, but sending is switched off in <a href="{{ route('admin.settings') }}#sale-panel-sync">Settings</a>.@endif
                <br><b>{{ $panel['sent'] }}</b> delivered {{ \Illuminate\Support\Str::plural('order', $panel['sent']) }} in the panel · <b>{{ $panel['unsent'] }}</b> not sent yet
            </p>
            @if ($panel['error'])<p class="err small">Last problem: {{ $panel['error'] }}</p>@endif
        @endif
    </div>
    @if ($panel['configured'])
        <div class="row-actions">
            <form method="post" action="{{ url('admin/sales/panel-test') }}">@csrf<button class="btn small ghost">Test connection</button></form>
            @if ($panel['unsent'])<form method="post" action="{{ url('admin/sales/panel-send') }}">@csrf<button class="btn small" data-confirm="Send {{ $panel['unsent'] }} delivered {{ \Illuminate\Support\Str::plural('order', $panel['unsent']) }} to the sale panel?">Send delivered orders now ({{ $panel['unsent'] }})</button></form>@endif
        </div>
    @endif
</section>

@if ($due->isNotEmpty())
<form method="post" action="{{ route('admin.sales.cod-received') }}" class="card" data-bulk>
    @csrf
    <h2>💵 COD to receive from Delhivery ({{ $due->count() }} · {{ money($due->sum('total')) }})</h2>
    <p class="muted small">Delhivery collects the cash and pays it into your bank in batches (remittance). When a payment arrives, tick the orders it covers, enter the date and the UTR / reference from the remittance report, and save.</p>
    <div class="tbl-wrap"><table class="tbl compact">
        <tr><th><input type="checkbox" data-check-all aria-label="Select all"></th><th>Delivered</th><th>Order</th><th>Customer</th><th>AWB</th><th class="right">Amount</th></tr>
        @foreach ($due as $o)
            <tr data-pick-row>
                <td><input type="checkbox" name="ids[]" value="{{ $o->id }}" data-check></td>
                <td>{{ ($o->delivered_at ?? $o->shipped_at ?? $o->created_at)->format('d M') }} <span class="muted small">· {{ ($o->delivered_at ?? $o->created_at)->diffForHumans(null, true) }}</span></td>
                <td><a href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td>
                <td>{{ $o->name }}</td>
                <td class="small">{{ $o->tracking_number }}</td>
                <td class="right">{{ money($o->total) }}</td>
            </tr>
        @endforeach
    </table></div>
    <div class="bulk-bar" data-bulk-bar hidden>
        <b data-bulk-count>0 selected</b>
        <label>Received on <input type="date" name="received_on" value="{{ today()->format('Y-m-d') }}" max="{{ today()->format('Y-m-d') }}" required></label>
        <label>UTR / reference <input name="ref" maxlength="100" placeholder="optional"></label>
        <button class="btn small">Mark COD received</button>
    </div>
</form>
@endif

<section class="card">
    <div class="sales-head">
        <h2>Delivered orders ({{ $rows->count() }})</h2>
        <div class="range">
            @foreach ([null => 'All', 'online' => 'Online', 'cod' => 'COD'] as $k => $l)
                <a class="btn small {{ $method === ($k ?: null) ? '' : 'ghost' }}" href="{{ $q(['method' => $k]) }}">{{ $l }}</a>
            @endforeach
        </div>
    </div>
    @if ($rows->isEmpty())
        <p class="muted">No delivered orders in this period.</p>
    @else
    <div class="tbl-wrap"><table class="tbl compact">
        <tr><th>Delivered</th><th>Invoice</th><th>Order</th><th>Customer</th><th>Payment</th><th class="right">Taxable</th><th class="right">GST</th><th class="right">Total</th><th>COD in bank</th></tr>
        @foreach ($rows as $row)
            @php $o = $row['order']; @endphp
            <tr>
                <td>{{ $row['date']->format('d M Y') }}</td>
                <td class="small">{{ $o->invoice_number }}</td>
                <td><a href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a></td>
                <td>{{ $o->name }}</td>
                <td><span class="badge {{ $o->payment_method === 'cod' ? 'warn' : 'ok' }}">{{ $row['method'] }}</span></td>
                <td class="right">{{ money($row['taxable']) }}</td>
                <td class="right">{{ money($row['gst']) }}</td>
                <td class="right"><b>{{ money($row['total']) }}</b></td>
                <td class="small">
                    @if ($o->payment_method !== 'cod') <span class="muted">—</span>
                    @elseif ($o->cod_remitted_at) ✓ {{ $o->cod_remitted_at->format('d M') }}@if ($o->cod_remittance_ref) <span class="muted">· {{ $o->cod_remittance_ref }}</span>@endif
                    @else <span class="badge warn">Due</span>
                    @endif
                </td>
            </tr>
        @endforeach
        <tr><th colspan="5">Total</th><th class="right">{{ money($gst['taxable']) }}</th><th class="right">{{ money($gst['gst']) }}</th><th class="right">{{ money($gst['total']) }}</th><th></th></tr>
    </table></div>
    @endif
</section>
@endsection
