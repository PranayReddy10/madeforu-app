@extends('admin.layout')
@section('title', 'Abandoned carts')
@section('content')
<div class="cards">
    @foreach ($stats as $label => $value)<div class="card stat"><span>{{ $label }}</span><strong>{{ $value }}</strong></div>@endforeach
</div>
<form class="filters card" method="get">
    <select name="show" onchange="this.form.submit()">
        <option value="">All</option>
        <option value="open" @selected(request('show') === 'open')>Not recovered</option>
        <option value="recovered" @selected(request('show') === 'recovered')>Recovered</option>
    </select>
    <span class="muted small">Carts are saved once a shopper types an email or phone at checkout. Reminders go after {{ setting('abandoned.first_hours', 1) }} h and {{ setting('abandoned.second_hours', 24) }} h ({{ setting('abandoned.enabled', true) ? 'on' : 'off' }} — <a href="{{ route('admin.settings') }}">settings</a>).</span>
</form>
<div class="card tbl-wrap">
<table class="tbl">
    <thead><tr><th>Shopper</th><th>Cart</th><th>Value</th><th>Left</th><th>Reminders</th><th>Status</th><th></th></tr></thead>
    <tbody>
    @forelse ($carts as $c)
        @php $items = $c->items(); $wa = \App\Services\WhatsApp::number((string) $c->phone); @endphp
        <tr>
            <td><strong>{{ $c->name ?: '—' }}</strong><br><small class="muted">{{ $c->email }}@if ($c->email && $c->phone) · @endif{{ $c->phone }}</small></td>
            <td><small>{{ collect($items)->map(fn ($i) => $i['product']->name.' × '.$i['qty'])->implode(', ') ?: '—' }}</small></td>
            <td>{{ money($c->value) }}</td>
            <td class="nowrap"><small>{{ $c->updated_at->diffForHumans() }}</small></td>
            <td>{{ $c->reminders_sent }}@if ($c->last_reminded_at)<br><small class="muted">{{ $c->last_reminded_at->format('d M, H:i') }}</small>@endif</td>
            <td>
                @if ($c->recovered_at)<span class="badge ok">Ordered</span>@if ($c->recoveredOrder) <a href="{{ route('admin.orders.show', $c->recoveredOrder) }}">{{ $c->recoveredOrder->number }}</a>@endif
                @elseif ($c->unsubscribed_at)<span class="badge">Unsubscribed</span>
                @else<span class="badge err">Open</span>@endif
            </td>
            <td class="nowrap">
                @if ($wa && ! $c->recovered_at)
                    <a class="btn small ghost" target="_blank" rel="noopener" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Hi'.($c->name ? ' '.\Illuminate\Support\Str::before($c->name, ' ') : '').'! This is '.config('store.name').' 😊 You left '.(collect($items)->first()['product']->name ?? 'a gift').' in your cart — your photos are saved. Finish your order here: '.$c->recoverUrl()) }}">WhatsApp</a>
                @endif
            </td>
        </tr>
    @empty
        <tr><td colspan="7" class="muted">No abandoned carts yet.</td></tr>
    @endforelse
    </tbody>
</table>
</div>
{{ $carts->links('admin.pagination') }}
@endsection
