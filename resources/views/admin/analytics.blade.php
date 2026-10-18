@extends('admin.layout')
@section('title', 'Analytics')
@section('actions')
    <form method="get" class="range">
        @foreach ([7 => '7 days', 30 => '30 days', 90 => '90 days'] as $d => $l)
            <button name="days" value="{{ $d }}" class="btn small {{ $days === $d ? '' : 'ghost' }}">{{ $l }}</button>
        @endforeach
    </form>
@endsection
@section('content')
@php
    $k = $kpis['now']; $ch = $kpis['change'];
    $delta = fn ($key) => $ch[$key] === null ? '' : '<em class="delta '.($ch[$key] >= 0 ? 'up' : 'down').'">'.($ch[$key] >= 0 ? '▲ ' : '▼ ').abs($ch[$key]).'%</em>';
    $num = fn ($n) => number_format((float) $n);
    $ga = $google['ga']; $gsc = $google['gsc'];
@endphp

{{-- 1. The shop's own numbers (always on, no setup) --}}
<div class="cards kpis">
    <div class="card stat"><span>Visitors</span><strong>{{ $num($k['visitors']) }}</strong>{!! $delta('visitors') !!}</div>
    <div class="card stat"><span>Page views</span><strong>{{ $num($k['views']) }}</strong>{!! $delta('views') !!}</div>
    <div class="card stat"><span>Orders</span><strong>{{ $num($k['orders']) }}</strong>{!! $delta('orders') !!}</div>
    <div class="card stat"><span>Revenue</span><strong>{{ money($k['revenue']) }}</strong>{!! $delta('revenue') !!}</div>
    <div class="card stat"><span>Conversion rate</span><strong>{{ number_format($k['conversion'], 1) }}%</strong><small class="muted">orders ÷ visitors</small></div>
    <div class="card stat"><span>Average order</span><strong>{{ money($k['aov']) }}</strong>{!! $delta('aov') !!}</div>
</div>
<p class="muted small" style="margin-top:-8px">Compared with the {{ $days }} days before. Counted by the shop itself (bots, and you while logged in as admin, are left out).</p>

<div class="card">
    <h2>Visitors &amp; orders per day</h2>
    @php
        $maxV = max(1, $daily->max('visitors')); $maxO = max(1, $daily->max('orders'));
        $n = $daily->count(); $w = 1000; $h = 220; $bw = $w / max(1, $n);
        $pts = $daily->values()->map(fn ($d, $i) => round($i * $bw + $bw / 2, 1).','.round($h - 10 - ($d['orders'] / $maxO) * ($h - 40), 1))->implode(' ');
    @endphp
    <svg class="chart" viewBox="0 0 {{ $w }} {{ $h + 24 }}" preserveAspectRatio="none" role="img" aria-label="Visitors and orders per day">
        @foreach ($daily->values() as $i => $d)
            @php $bh = ($d['visitors'] / $maxV) * ($h - 40); @endphp
            <rect x="{{ $i * $bw + $bw * .15 }}" y="{{ $h - 10 - $bh }}" width="{{ $bw * .7 }}" height="{{ max(1, $bh) }}" rx="3" class="bar"><title>{{ \Illuminate\Support\Carbon::parse($d['day'])->format('D j M') }}: {{ $d['visitors'] }} visitors, {{ $d['orders'] }} orders, {{ money($d['revenue']) }}</title></rect>
            @if ($n <= 31 || $i % 7 === 0)<text x="{{ $i * $bw + $bw / 2 }}" y="{{ $h + 16 }}" class="lbl">{{ \Illuminate\Support\Carbon::parse($d['day'])->format($n <= 7 ? 'D' : 'j') }}</text>@endif
        @endforeach
        <polyline points="{{ $pts }}" class="line"/>
    </svg>
    <p class="legend"><span class="sw bar"></span> Visitors (max {{ $maxV }}) <span class="sw line"></span> Orders (max {{ $maxO }})</p>
</div>

<div class="grid2">
    <div class="card">
        <h2>Shopping funnel</h2>
        @php
            $steps = [['Visited the shop', $k['visitors']], ['Looked at a product', $k['product_viewers']], ['Added to cart', $k['carts']], ['Reached checkout', $k['checkouts']], ['Ordered', $k['buyers']]];
            $top = max(1, $k['visitors']);
        @endphp
        <div class="funnel">
            @foreach ($steps as $i => [$label, $v])
                <div class="f-row">
                    <span>{{ $label }}</span>
                    <div class="f-bar"><i style="width: {{ max(2, round(100 * $v / $top)) }}%"></i></div>
                    <b>{{ $num($v) }}</b>
                    <small class="muted">@if ($i){{ $steps[$i - 1][1] ? round(100 * $v / $steps[$i - 1][1]) : 0 }}% of previous @endif</small>
                </div>
            @endforeach
        </div>
        <p class="muted small">Big drop between two steps = the place to improve (product photos & price → cart; delivery cost → checkout; payment → order).</p>
    </div>
    <div class="card">
        <h2>Where visitors come from</h2>
        <table class="tbl compact">
            <thead><tr><th>Source</th><th>Visitors</th><th>Orders</th><th>Revenue</th><th>Conv.</th></tr></thead>
            <tbody>
            @forelse ($sources as $s)
                <tr><td><b>{{ $s->source }}</b></td><td>{{ $num($s->visitors) }}</td><td>{{ $s->buyers }}</td><td>{{ money($s->revenue) }}</td><td>{{ number_format($s->conversion, 1) }}%</td></tr>
            @empty
                <tr><td colspan="5" class="muted">No visits recorded yet — they appear as soon as people browse the shop.</td></tr>
            @endforelse
            </tbody>
        </table>
        @php $devTotal = max(1, $devices->sum()); @endphp
        <div class="devices">
            @foreach (['mobile' => '📱 Phone', 'desktop' => '💻 Computer', 'tablet' => 'Tablet'] as $dk => $dl)
                @if ($devices[$dk] ?? 0)<span>{{ $dl }} <b>{{ round(100 * $devices[$dk] / $devTotal) }}%</b></span>@endif
            @endforeach
        </div>
        <p class="muted small">Tip: add <code>?utm_source=instagram</code> (or whatsapp, facebook…) to links you share, to see exactly which posts bring orders.</p>
    </div>
</div>

<div class="grid2">
    <div class="card">
        <h2>Products: views → carts → sales</h2>
        <table class="tbl compact">
            <thead><tr><th>Product</th><th>Views</th><th>Carts</th><th>Sold</th><th>Revenue</th></tr></thead>
            <tbody>
            @forelse ($products as $p)
                <tr>
                    <td><a href="{{ $p->product->url() }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit($p->product->name, 46) }}</a>
                        @if ($p->views >= 20 && ! $p->carts)<br><span class="badge warn">many views, no carts</span>@endif</td>
                    <td>{{ $num($p->views) }}</td><td>{{ $p->carts }}</td><td>{{ $p->sold }}</td><td>{{ money($p->revenue) }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="muted">No product views yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="card">
        <h2>Most viewed pages</h2>
        <table class="tbl compact">
            <thead><tr><th>Page</th><th>Views</th><th>Visitors</th></tr></thead>
            <tbody>
            @forelse ($pages as $p)
                <tr><td><a href="{{ url($p->path) }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit(urldecode($p->path), 50) }}</a></td><td>{{ $num($p->views) }}</td><td>{{ $num($p->visitors) }}</td></tr>
            @empty
                <tr><td colspan="3" class="muted">—</td></tr>
            @endforelse
            </tbody>
        </table>
        @if ($searches->isNotEmpty())
            <h3 style="margin-top:16px">What people search for</h3>
            <div class="chips">@foreach ($searches as $s)<span class="chip {{ $s->results ? '' : 'warn' }}" title="{{ $s->results ? $s->results.' results' : 'No results — add products or keywords' }}">{{ $s->term }} <b>{{ $s->n }}</b></span>@endforeach</div>
        @endif
    </div>
</div>

{{-- 2. Google --}}
<div class="section-title">
    <h2>Google</h2>
    <form method="post" action="{{ route('admin.analytics.refresh') }}">@csrf<button class="btn small ghost">↻ Refresh Google &amp; Instagram</button></form>
</div>
@unless (\App\Services\Google::credentials())
    <div class="card setup">
        <h3>Connect Google Analytics &amp; Search Console</h3>
        <ol class="small">
            <li>Go to <a href="https://console.cloud.google.com/" target="_blank" rel="noopener">Google Cloud Console</a> → create a project → enable <b>Google Analytics Data API</b> and <b>Google Search Console API</b>.</li>
            <li>IAM → Service accounts → Create → Keys → Add key → <b>JSON</b> (a file downloads).</li>
            <li>GA4 → Admin → Property access management → add the service account email as <b>Viewer</b>. Search Console → Settings → Users → add it with <b>Full</b> permission.</li>
            <li>Paste the JSON file and your GA4 property ID in <a href="{{ route('admin.settings') }}#google-analytics-search-console-reports">Settings → Google Analytics &amp; Search Console reports</a>.</li>
        </ol>
    </div>
@endunless

<div class="grid2">
    <div class="card">
        <h2>Google Analytics (GA4)</h2>
        @if ($google['ga_error'])<p class="flash err">Google Analytics: {{ $google['ga_error'] }}</p>
        @elseif (! $ga)<p class="muted">Not connected yet. @if (setting('analytics.ga4'))Your site already sends data to GA4 ({{ setting('analytics.ga4') }}) — add the property ID and service account to see it here.@endif</p>
        @else
            @php [$users, $sessions, $pv, $eng, $dur, $conv] = array_pad($ga['totals'], 6, 0); @endphp
            <div class="mini-stats">
                <div><span>Users</span><b>{{ $num($users) }}</b></div>
                <div><span>Sessions</span><b>{{ $num($sessions) }}</b></div>
                <div><span>Page views</span><b>{{ $num($pv) }}</b></div>
                <div><span>Engaged</span><b>{{ round($eng * 100) }}%</b></div>
                <div><span>Avg. visit</span><b>{{ gmdate('i:s', (int) $dur) }}</b></div>
                <div><span>Key events</span><b>{{ $num($conv) }}</b></div>
            </div>
            <h3>Channels</h3>
            <table class="tbl compact"><tbody>@foreach ($ga['channels'] as $row)<tr><td>{{ $row['dims'][0] }}</td><td>{{ $num($row['mets'][0]) }} sessions</td><td>{{ $num($row['mets'][1]) }} users</td></tr>@endforeach</tbody></table>
            <h3>Top pages</h3>
            <table class="tbl compact"><tbody>@foreach ($ga['pages'] as $row)<tr><td>{{ \Illuminate\Support\Str::limit($row['dims'][0], 50) }}</td><td>{{ $num($row['mets'][0]) }} views</td></tr>@endforeach</tbody></table>
            <h3>Cities</h3>
            <div class="chips">@foreach ($ga['cities'] as $row)<span class="chip">{{ $row['dims'][0] }} <b>{{ $num($row['mets'][0]) }}</b></span>@endforeach</div>
        @endif
    </div>
    <div class="card">
        <h2>Google Search (Search Console)</h2>
        @if ($google['gsc_error'])<p class="flash err">Search Console: {{ $google['gsc_error'] }}</p>
        @elseif (! $gsc)<p class="muted">Not connected yet. Shows the searches people use to find you, clicks, and your position on Google.</p>
        @else
            @php $t = $gsc['totals']; @endphp
            <div class="mini-stats">
                <div><span>Clicks</span><b>{{ $num($t['clicks'] ?? 0) }}</b></div>
                <div><span>Impressions</span><b>{{ $num($t['impressions'] ?? 0) }}</b></div>
                <div><span>Click rate</span><b>{{ number_format(100 * ($t['ctr'] ?? 0), 1) }}%</b></div>
                <div><span>Avg. position</span><b>{{ number_format($t['position'] ?? 0, 1) }}</b></div>
            </div>
            <h3>Searches that find you</h3>
            <table class="tbl compact">
                <thead><tr><th>Search</th><th>Clicks</th><th>Shown</th><th>Position</th></tr></thead>
                <tbody>@forelse ($gsc['queries'] as $q)<tr><td>{{ $q['keys'][0] }}</td><td>{{ $q['clicks'] }}</td><td>{{ $num($q['impressions']) }}</td><td>{{ number_format($q['position'], 1) }}</td></tr>@empty<tr><td colspan="4" class="muted">No search data yet (Google needs a few days).</td></tr>@endforelse</tbody>
            </table>
            <h3>Pages getting Google clicks</h3>
            <table class="tbl compact"><tbody>@foreach ($gsc['pages'] as $p)<tr><td>{{ \Illuminate\Support\Str::limit(str_replace(url('/'), '', $p['keys'][0]) ?: '/', 50) }}</td><td>{{ $p['clicks'] }} clicks</td><td>{{ $num($p['impressions']) }} shown</td></tr>@endforeach</tbody></table>
            <h3>Sitemaps</h3>
            <table class="tbl compact"><tbody>
                @forelse ($gsc['sitemaps'] as $sm)
                    <tr><td>{{ str_replace(url('/'), '', $sm['path']) }}</td><td>{{ ! empty($sm['lastDownloaded']) ? 'read '.\Illuminate\Support\Carbon::parse($sm['lastDownloaded'])->diffForHumans() : 'not read yet' }}</td>
                        <td>@if (! empty($sm['errors']))<span class="badge err">{{ $sm['errors'] }} errors</span>@elseif (! empty($sm['isPending']))<span class="badge">pending</span>@else<span class="badge ok">OK</span>@endif</td></tr>
                @empty<tr><td class="muted">No sitemap submitted yet.</td></tr>@endforelse
            </tbody></table>
            <form method="post" action="{{ route('admin.analytics.sitemap') }}">@csrf<button class="btn small">Submit sitemap to Google</button></form>
        @endif
    </div>
</div>

<div class="card">
    <h2>Google index — are your pages on Google?</h2>
    @if (! \App\Services\Google::searchReady())
        <p class="muted">Connect Search Console above. Every night the shop then asks Google, page by page, whether each product, category and page is indexed.</p>
    @elseif (! $index['checked'])
        <p class="muted">Not checked yet.</p>
        <form method="post" action="{{ route('admin.analytics.index-check') }}">@csrf<button class="btn small">Check now</button></form>
    @else
        <div class="mini-stats">
            <div><span>Indexed</span><b class="ok">{{ $index['indexed'] }}</b></div>
            <div><span>Not indexed</span><b class="err">{{ $index['checked'] - $index['indexed'] }}</b></div>
            <div><span>Waiting to check</span><b>{{ $index['total'] - $index['checked'] }}</b></div>
            <div><span>Last check</span><b>{{ \Illuminate\Support\Carbon::parse($index['lastCheck'])->diffForHumans() }}</b></div>
        </div>
        <div class="chips">@foreach ($index['byCoverage'] as $cov => $n)<span class="chip">{{ $cov ?: 'Unknown' }} <b>{{ $n }}</b></span>@endforeach</div>
        @if ($index['notIndexed']->isNotEmpty())
            <h3>Not on Google yet</h3>
            <table class="tbl compact">
                <thead><tr><th>Page</th><th>Type</th><th>Google says</th><th>Last crawled</th></tr></thead>
                <tbody>@foreach ($index['notIndexed'] as $r)<tr><td><a href="{{ $r->url }}" target="_blank" rel="noopener">{{ \Illuminate\Support\Str::limit(str_replace(url('/'), '', $r->url), 55) }}</a></td><td>{{ $r->kind }}</td><td>{{ $r->coverage }}</td><td>{{ $r->last_crawl_at ? \Illuminate\Support\Carbon::parse($r->last_crawl_at)->format('d M Y') : 'never' }}</td></tr>@endforeach</tbody>
            </table>
            <p class="muted small">“Discovered/Crawled – currently not indexed”: give the page a unique description, good photos and links from other pages; then use “Request indexing” in Search Console.</p>
        @endif
        <form method="post" action="{{ route('admin.analytics.index-check') }}" style="margin-top:10px">@csrf<button class="btn small ghost">Check more now</button></form>
    @endif
</div>

{{-- 3. Meta, Merchant feeds, Instagram --}}
<div class="card">
    <h2>Meta (Facebook &amp; Instagram), Google Shopping &amp; Instagram</h2>
    <table class="tbl compact">
        <tbody>
            <tr><td><b>Meta Pixel</b><br><small class="muted">Tracks views, add-to-cart and purchases for Facebook/Instagram ads</small></td>
                <td>@if ($feeds['pixel'])<span class="badge ok">On</span>@else<span class="badge warn">Not set</span> <a href="{{ route('admin.settings') }}">add Pixel ID</a>@endif</td></tr>
            <tr><td><b>Meta catalog feed</b> (Instagram &amp; Facebook Shop)<br><small class="muted"><code>{{ route('feed.facebook') }}</code> — Commerce Manager → Catalog → Data sources → Data feed → schedule daily</small></td>
                <td>@if ($feeds['facebook'])<span class="badge ok">Synced</span> {{ \Illuminate\Support\Carbon::parse($feeds['facebook']['at'])->diffForHumans() }}@else<span class="badge warn">Not fetched yet</span>@endif<br><small class="muted">{{ $feeds['products'] }} products</small></td></tr>
            <tr><td><b>Google Merchant Center feed</b> (Google Shopping, free listings)<br><small class="muted"><code>{{ route('feed.google') }}</code> — Merchant Center → Products → Feeds → Scheduled fetch</small></td>
                <td>@if ($feeds['google'])<span class="badge ok">Synced</span> {{ \Illuminate\Support\Carbon::parse($feeds['google']['at'])->diffForHumans() }}@else<span class="badge warn">Not fetched yet</span>@endif</td></tr>
            <tr><td><b>Google Analytics / Tag Manager tag</b></td><td>@if ($feeds['ga4_tag'])<span class="badge ok">On</span>@else<span class="badge warn">Not set</span>@endif</td></tr>
            <tr><td><b>Instagram posts on the home page</b><br><small class="muted">{{ config('store.social.instagram') }}</small></td>
                <td>@if ($feeds['instagram'])<span class="badge ok">Connected</span> {{ $feeds['instagram_posts'] }} posts @else<span class="badge">Follow band only</span> <a href="{{ route('admin.settings') }}">add token</a>@endif</td></tr>
        </tbody>
    </table>
</div>
@endsection
