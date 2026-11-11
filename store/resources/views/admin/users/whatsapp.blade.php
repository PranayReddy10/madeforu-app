@extends('admin.layout')
@section('title', 'WhatsApp your customers')
@section('actions')<a class="btn ghost" href="{{ route('admin.users.whatsapp', ['show' => $filter, 'download' => 1]) }}">⬇ Download list (CSV)</a>@endsection
@section('content')
<form method="post" action="{{ route('admin.users.whatsapp.message') }}" class="card">
    @csrf
    <h2>Message</h2>
    <p class="muted small"><b>{name}</b> becomes the customer's first name, <b>{link}</b> your {{ \App\Support\Campaign::active() ? \App\Support\Campaign::name().' landing page' : 'website' }}. Each <b>Send</b> button opens WhatsApp with the message ready — you just press send.</p>
    <textarea name="message" rows="4" maxlength="1000" data-wa-message>{{ old('message', $message) }}</textarea>
    <div class="row-inline"><button class="btn small">Save message</button><span class="muted small" data-wa-left></span></div>
</form>

<nav class="ol-tabs">
    @foreach (['buyers' => 'Bought before', 'recent' => 'Ordered in last 90 days', 'lapsed' => 'Not ordered in 90+ days', 'all' => 'Everyone with a number'] as $k => $l)
        <a href="{{ route('admin.users.whatsapp', ['show' => $k]) }}" class="{{ $filter === $k ? 'on' : '' }}">{{ $l }} <b>{{ $counts[$k] }}</b></a>
    @endforeach
</nav>

<div class="card" data-wa-list data-link="{{ $link }}">
    <p class="muted small">Tip: send a few at a time (20–30 an hour) so WhatsApp doesn't flag your number. For hundreds, import the CSV into WhatsApp Business → Broadcast lists (customers who saved your number receive broadcasts).</p>
    <table class="tbl">
        <thead><tr><th>Customer</th><th>Phone</th><th>Orders</th><th>Spent</th><th>Last order</th><th></th></tr></thead>
        <tbody>
        @forelse ($list as $r)
            <tr data-wa-row="{{ $r->phone }}">
                <td><b>{{ $r->name ?: 'Customer' }}</b>@if ($r->city)<br><small class="muted">{{ $r->city }}</small>@endif</td>
                <td>+91 {{ $r->phone }}</td>
                <td>{{ $r->orders }}</td>
                <td>{{ money($r->spent) }}</td>
                <td>{{ $r->last?->format('d M Y') ?? '—' }}</td>
                <td><a class="btn small wa-send" target="_blank" rel="noopener" data-wa-send data-phone="91{{ $r->phone }}" data-name="{{ \Illuminate\Support\Str::of($r->name ?: 'there')->trim()->before(' ')->title() }}">Send</a></td>
            </tr>
        @empty
            <tr><td colspan="6" class="muted">No customers with a mobile number here yet.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>
@endsection
