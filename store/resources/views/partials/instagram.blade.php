{{-- Home page: latest Instagram posts (or, until a token is set, a follow band with our own product photos). --}}
@php
    $posts = \App\Services\Instagram::posts(8);
    $handle = \App\Services\Instagram::handle();
    $igUrl = config('store.social.instagram');
@endphp
@if($igUrl)
<section class="section insta">
    <div class="wrap">
        <div class="insta-head">
            <span class="insta-logo">@include('partials.icon', ['i' => 'instagram'])</span>
            <div><h2>{{ $title ?? 'Follow us on Instagram' }}</h2><p><a href="{{ $igUrl }}" target="_blank" rel="noopener">{{ $handle }}</a> · new designs, happy customers &amp; behind the scenes</p></div>
            <a class="btn insta-btn" href="{{ $igUrl }}" target="_blank" rel="noopener">Follow</a>
        </div>
        @php
            $tiles = $posts ?: $fallback->filter(fn ($p) => $p->images->isNotEmpty())->take(8)->map(fn ($p) => ['image' => $p->thumbUrl(), 'link' => $p->url(), 'caption' => $p->name, 'video' => false, 'own' => true])->values()->all();
        @endphp
        @if(count($tiles))
        <div class="insta-grid">
            @foreach($tiles as $t)
                <a href="{{ $t['link'] }}" @if(empty($t['own'])) target="_blank" rel="noopener" @endif class="insta-tile" aria-label="{{ $t['caption'] ?: 'Instagram post' }}">
                    <img src="{{ $t['image'] }}" alt="{{ $t['caption'] }}" loading="lazy" width="300" height="300">
                    <span class="insta-over">@include('partials.icon', ['i' => empty($t['own']) ? 'instagram' : 'heart'])</span>
                    @if($t['video'])<span class="insta-play">▶</span>@endif
                </a>
            @endforeach
        </div>
        @endif
    </div>
</section>
@endif
