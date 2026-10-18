@extends('admin.layout')
@section('title', 'Import from WordPress')
@section('content')
@php $status = $state['status'] ?? null; $busy = \App\Services\ImportQueue::busy(); @endphp
@if ($busy)<meta http-equiv="refresh" content="5">@endif
<div class="cards">
    @foreach ($counts as $label => $n)<div class="card stat"><span>{{ $label }} in this store</span><strong>{{ number_format($n) }}</strong></div>@endforeach
</div>

@if ($status)
<section class="card">
    <h2>Last import
        @if ($status === 'queued')<span class="badge">Waiting to start</span>
        @elseif ($status === 'running' && $busy)<span class="badge">Running…</span>
        @elseif ($status === 'running')<span class="badge err">Stopped</span>
        @elseif ($status === 'done')<span class="badge ok">Finished</span>
        @else<span class="badge err">Failed</span>@endif
    </h2>
    <p class="muted small">{{ $state['url'] ?? '' }} · queued {{ $state['queued_at'] ?? '' }}@if (! empty($state['finished_at'])) · finished {{ $state['finished_at'] }}@endif</p>
    @if ($status === 'queued')
        <p>The cron job starts it within a minute. If nothing happens, the cron job isn't set up yet — run it here instead:</p>
        <form method="post" action="{{ route('admin.import.run') }}" onsubmit="this.querySelector('button').disabled=true;this.querySelector('button').textContent='Importing… keep this tab open (1–5 min)'">@csrf<button class="btn">Run now</button></form>
    @elseif ($status === 'running' && ! $busy)
        <p>The import stopped part-way (the server's time limit). Start it again below — products already copied are updated, not duplicated.</p>
    @endif
    @if (! empty($state['error']))<p class="flash err">{{ $state['error'] }}</p>@endif
    @if ($log !== '')<pre class="log" style="max-height:420px;overflow:auto;white-space:pre-wrap;font-size:12px;background:#f6f6f8;padding:12px;border-radius:8px">{{ $log }}</pre>@endif
</section>
@endif

@unless ($busy)
<form method="post" action="{{ route('admin.import.store') }}" class="card narrow">
    @csrf
    <h2>Copy products, categories, customers and orders from WooCommerce</h2>
    <p class="muted small">Run this while the WordPress site is still live. You can run it again any time: everything is matched by its WooCommerce id, so nothing is duplicated.</p>
    <label>WordPress site<input type="url" name="url" value="{{ old('url', config('store.woo.url') ?: 'https://madeforu.co.in') }}" required></label>
    <label>Consumer key <small class="muted">WooCommerce → Settings → Advanced → REST API → Add key (Read). Needed for drafts, customers and orders.</small>
        <input type="text" name="key" value="" placeholder="ck_…" autocomplete="off"></label>
    <label>Consumer secret<input type="password" name="secret" value="" placeholder="cs_…" autocomplete="new-password"></label>
    <input type="hidden" name="customers" value="0">
    <label class="check"><input type="checkbox" name="customers" value="1" checked> Also import customers, past orders (with their photos) and coupons</label>
    <input type="hidden" name="images" value="0">
    <label class="check"><input type="checkbox" name="images" value="1" checked> Copy images (images already on the DigitalOcean Spaces CDN keep their URL)</label>
    <p class="muted small">The keys are used for this one run and then deleted. Afterwards you can revoke the key in WooCommerce.</p>
    <button class="btn">Start import</button>
</form>
@endunless
@endsection
