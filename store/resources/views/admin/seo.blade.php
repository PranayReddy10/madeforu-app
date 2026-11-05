@extends('admin.layout')
@section('title', 'SEO')
@section('content')
<div class="cards">
    <div class="card stat"><span>Average product SEO score</span><strong>{{ $average }}</strong><small>{{ $products->count() }} products</small></div>
    <div class="card stat"><span>Products scoring under 50</span><strong>{{ $products->where('score', '<', 50)->count() }}</strong><small>fix these first</small></div>
    <div class="card stat"><span>Categories without a meta description</span><strong>{{ $categories->count() }}</strong><small>&nbsp;</small></div>
</div>

<form method="post" action="{{ route('admin.seo.fill') }}" class="card speed-card">
    @csrf
    <div>
        <b>Fill in SEO for every product and category</b>
        <p class="muted small">Writes a focus keyword, meta title (30–60 characters), meta description (70–160), search keywords and photo alt text from each product's name, categories and description — only where they're missing or too short/long. Titles and descriptions you wrote yourself that pass the check are kept.</p>
    </div>
    <button class="btn primary">Auto-fill SEO</button>
</form>

<div class="card">
    <h2>Search engine links</h2>
    <ul class="links">
        <li>Sitemap: <a href="{{ url('sitemap.xml') }}" target="_blank" rel="noopener">{{ url('sitemap.xml') }}</a> — submit in Google Search Console and Bing Webmaster Tools</li>
        <li>Google Merchant Center feed: <a href="{{ route('feed.google') }}" target="_blank" rel="noopener">{{ route('feed.google') }}</a></li>
        <li>Facebook / Instagram catalogue: <a href="{{ route('feed.facebook') }}" target="_blank" rel="noopener">{{ route('feed.facebook') }}</a></li>
        <li><a href="{{ url('robots.txt') }}" target="_blank" rel="noopener">robots.txt</a> · <a href="{{ route('admin.settings') }}">Verification codes &amp; analytics IDs</a></li>
    </ul>
</div>

<div class="card">
    <h2>Products, worst first</h2>
    <table class="tbl">
        <thead><tr><th>Score</th><th>Product</th><th>What to fix</th></tr></thead>
        <tbody>
        @forelse ($products as $row)
            <tr>
                <td><span class="score {{ $row['score'] >= 80 ? 'good' : ($row['score'] >= 50 ? 'mid' : 'bad') }}">{{ $row['score'] }}</span></td>
                <td><a href="{{ route('admin.products.edit', $row['product']) }}">{{ $row['product']->name }}</a></td>
                <td>@if ($row['issues'])<ul class="issues">@foreach ($row['issues'] as $i)<li>{{ $i }}</li>@endforeach</ul>@else<span class="muted">All good</span>@endif</td>
            </tr>
        @empty
            <tr><td colspan="3" class="muted">No products.</td></tr>
        @endforelse
        </tbody>
    </table>
</div>

@if ($categories->isNotEmpty())
<div class="card">
    <h2>Categories missing a meta description</h2>
    <ul class="links">
        @foreach ($categories as $c)<li><a href="{{ route('admin.categories.edit', $c) }}">{{ $c->name }}</a> <small class="muted">/product-category/{{ $c->path }}/</small></li>@endforeach
    </ul>
</div>
@endif
@endsection
