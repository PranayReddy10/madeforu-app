@extends('admin.layout')
@section('title', 'Dashboard')
@section('content')
<div class="cards">
    @foreach ($revenue as $label => [$amount, $count])
        <div class="card stat"><span>{{ $label }}</span><strong>{{ money($amount) }}</strong><small>{{ $count }} {{ \Illuminate\Support\Str::plural('order', $count) }}</small></div>
    @endforeach
    <a class="card stat profit-card {{ $profit['profit'] < 0 ? 'neg' : '' }}" href="{{ route('admin.profit') }}"><span>Profit, last 30 days</span><strong>{{ money($profit['profit']) }}</strong><small>{{ number_format($profit['margin'], 1) }}% of sales @if ($profit['missing'])· some costs missing @endif →</small></a>
</div>

@if (! $speed['direct'] || $speed['missing'])
<form method="post" action="{{ route('admin.speed') }}" class="card speed-card">
    @csrf
    <div>
        <b>Make the website faster</b>
        <p class="muted small">
            @unless ($speed['direct'])Photos are sent through the app one by one (slow on shared hosting). @endunless
            @if ($speed['missing']){{ $speed['missing'] }} product {{ \Illuminate\Support\Str::plural('photo', $speed['missing']) }} still {{ $speed['missing'] === 1 ? 'needs' : 'need' }} a small, light copy for product cards. @endif
            One click fixes it — safe to press again.
        </p>
    </div>
    <button class="btn primary">Speed up now</button>
</form>
@endif

<a class="card traffic-strip" href="{{ route('admin.analytics') }}">
    <span><b>{{ number_format($traffic['visitors']) }}</b> visitors this week</span>
    <span><b>{{ number_format($traffic['product_viewers']) }}</b> looked at products</span>
    <span><b>{{ number_format($traffic['carts']) }}</b> added to cart</span>
    <span><b>{{ number_format($traffic['conversion'], 1) }}%</b> ordered</span>
    @if ($topSource)<span>Top source <b>{{ $topSource->source }}</b></span>@endif
    <span class="go">Full analytics, Google &amp; Instagram →</span>
</a>

<div class="card">
    <h2>Orders by status</h2>
    <div class="chips">
        @foreach (\App\Models\Order::STATUSES as $key => $label)
            <a class="chip" href="{{ route('admin.orders.index', ['status' => $key]) }}">{{ $label }} <b>{{ $byStatus[$key] ?? 0 }}</b></a>
        @endforeach
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h2>Recent orders</h2>
        <table class="tbl">
            <thead><tr><th>Order</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
            <tbody>
            @forelse ($recent as $o)
                <tr>
                    <td><a href="{{ route('admin.orders.show', $o) }}">{{ $o->number }}</a><br><small>{{ $o->created_at->format('d M, H:i') }}</small></td>
                    <td>{{ $o->name }}<br><small>{{ $o->phone }}</small></td>
                    <td>{{ money($o->total) }}<br><small>{{ strtoupper($o->payment_method) }} · {{ $o->payment_status }}</small></td>
                    <td><span class="badge s-{{ $o->status }}">{{ $o->statusLabel() }}</span></td>
                </tr>
            @empty
                <tr><td colspan="4" class="muted">No orders yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    @if ($lowStock->isNotEmpty())
    <div class="card">
        <h2>Low stock</h2>
        <table class="tbl">
            <tbody>
            @foreach ($lowStock as $r)
                <tr><td><a href="{{ route('admin.products.edit', $r['product']) }}">{{ $r['name'] }}</a></td><td class="right"><span class="badge {{ $r['qty'] ? '' : 'err' }}">{{ $r['qty'] ? $r['qty'].' left' : 'Sold out' }}</span></td></tr>
            @endforeach
            </tbody>
        </table>
    </div>
    @endif
    <div class="card">
        <h2>Top products</h2>
        <table class="tbl">
            <thead><tr><th>Product</th><th>Sold</th><th>Views</th></tr></thead>
            <tbody>
            @forelse ($topProducts as $p)
                <tr><td><a href="{{ route('admin.products.edit', $p) }}">{{ $p->name }}</a></td><td>{{ $p->sales_count }}</td><td>{{ $p->views }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No sales yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h2>Top searches <small class="muted">last 30 days</small></h2>
        <table class="tbl">
            <thead><tr><th>Term</th><th>Searches</th><th>Results</th></tr></thead>
            <tbody>
            @forelse ($topSearches as $s)
                <tr><td>{{ $s->term }}</td><td>{{ $s->n }}</td><td>{{ $s->results }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">No searches yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>Searches with no results</h2>
        <p class="muted">Customers looked for these and found nothing. Add products, keywords or a redirect.</p>
        <table class="tbl">
            <thead><tr><th>Term</th><th>Searches</th></tr></thead>
            <tbody>
            @forelse ($zeroSearches as $s)
                <tr><td>{{ $s->term }}</td><td>{{ $s->n }}</td></tr>
            @empty
                <tr><td colspan="2" class="muted">None — good.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
