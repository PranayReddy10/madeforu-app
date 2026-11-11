@extends('admin.layout')
@section('title', 'Profit')
@section('actions')
    <form method="get" class="range">
        @foreach ([1 => 'Today', 7 => '7 days', 30 => '30 days', 90 => '90 days', 365 => '1 year'] as $d => $l)
            <button name="days" value="{{ $d }}" class="btn small {{ $days === $d ? '' : 'ghost' }}">{{ $l }}</button>
        @endforeach
    </form>
@endsection
@section('content')
<div class="cards">
    <div class="card stat"><span>Sales</span><strong>{{ money($s['revenue']) }}</strong><small>{{ $s['orders'] }} {{ \Illuminate\Support\Str::plural('order', $s['orders']) }}</small></div>
    <div class="card stat profit-card {{ $s['profit'] < 0 ? 'neg' : '' }}"><span>Profit</span><strong>{{ money($s['profit']) }}</strong><small>{{ number_format($s['margin'], 1) }}% of sales</small></div>
    <div class="card stat"><span>Profit per order</span><strong>{{ money($s['per_order']) }}</strong><small>after every cost below</small></div>
</div>

@if ($s['missing'])
    <div class="flash err">{{ $s['missing'] }} order {{ \Illuminate\Support\Str::plural('line', $s['missing']) }} have no cost yet, so profit is shown too high. Add “Cost to make” on those products (marked below).</div>
@endif

<div class="grid2">
    <div class="card">
        <h2>Where the money went</h2>
        @php
            $rows = [['Sales (incl. delivery & COD charges paid)', $s['revenue'], 'in'], ['Making the products', $s['making'], 'out'], ['Packing', $s['packing'], 'out'],
                ['Courier', $s['courier'], 'out'], ['Payment gateway fees', $s['fees'], 'out']];
            if ($s['returned']) { $rows[] = ['Returned parcels ('.$s['returned'].') — made, sent both ways, no money in', $s['returns'], 'out']; }
            $max = max(1, $s['revenue']);
        @endphp
        <div class="pf-rows">
            @foreach ($rows as [$label, $v, $dir])
                <div class="pf-row {{ $dir }}">
                    <span>{{ $label }}</span>
                    <b>{{ $dir === 'out' ? '−' : '' }}{{ money($v) }}</b>
                    <i style="width: {{ min(100, max(1, round(100 * $v / $max))) }}%"></i>
                </div>
            @endforeach
            <div class="pf-row total {{ $s['profit'] < 0 ? 'neg' : '' }}"><span>Profit</span><b>{{ money($s['profit']) }}</b></div>
        </div>
        <p class="muted small">Packing {{ money((float) setting('profit.packing')) }}/order · gateway {{ (float) setting('profit.gateway_percent') }}% of online orders · courier {{ setting('profit.shipping_mode', 'charged') === 'charged' ? 'as charged to the customer' : money((float) setting('profit.shipping_fixed')).'/order' }}. <a href="{{ route('admin.settings') }}#live-preview-profit">Change in Settings</a>.</p>
    </div>

    <div class="card">
        <h2>Products</h2>
        <table class="tbl">
            <thead><tr><th>Product</th><th>Sold</th><th>Sales</th><th>Cost</th><th>Profit</th><th>Margin</th></tr></thead>
            <tbody>
            @forelse ($products as $r)
                <tr>
                    <td>@if ($r->product)<a href="{{ route('admin.products.edit', $r->product) }}">{{ \Illuminate\Support\Str::limit($r->name, 60) }}</a>@else{{ $r->name }}@endif</td>
                    <td>{{ $r->units }}</td>
                    <td>{{ money($r->sales) }}</td>
                    <td>@if ($r->cost === null)<span class="badge warn">set cost</span>@else{{ money($r->cost) }}@endif</td>
                    <td>{{ $r->profit === null ? '—' : money($r->profit) }}</td>
                    <td>@if ($r->margin !== null)<span class="badge {{ $r->margin >= 40 ? 'ok' : ($r->margin >= 20 ? 'warn' : 'err') }}">{{ round($r->margin) }}%</span>@else — @endif</td>
                </tr>
            @empty
                <tr><td colspan="6" class="muted">No sales in this period.</td></tr>
            @endforelse
            </tbody>
        </table>
        <p class="muted small">Product profit = what customers paid for the item − its making cost (before packing, courier and fees).</p>
    </div>
</div>
@endsection
