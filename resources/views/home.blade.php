@extends('layouts.app')
@section('body_class', 'page-home')
@section('content')

@if($banners->isNotEmpty())<h1 class="sr">{{ config('store.name') }} - personalised photo gifts online in India</h1>@endif
<div class="wrap hero-wrap">@include('partials.hero-slider')</div>

<div class="usp-bar" aria-label="Why shop with us">
    <div class="wrap usp-track">
        <span>@include('partials.icon', ['i' => 'hand']) Hand-made to order</span>
        <span>@include('partials.icon', ['i' => 'truck']) Free shipping over {{ money(setting('free_shipping_over')) }}</span>
        @if(setting('cod_enabled'))<span>@include('partials.icon', ['i' => 'cash']) Cash on delivery</span>@endif
        <span>@include('partials.icon', ['i' => 'shield']) Secure UPI &amp; cards</span>
        @if($rating['count'] >= 3)<span>@include('partials.icon', ['i' => 'star']) {{ $rating['avg'] }}★ from {{ $rating['count'] }} reviews</span>@endif
    </div>
</div>

@if($categories->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>Shop by category</h2><p>Every one made with your photos</p></div><a href="{{ route('categories') }}">See all</a></div>
        <div class="cat-bubbles">
            @foreach($categories as $c)
                <a href="{{ $c->url() }}" class="bubble">
                    <span class="bubble-img">
                        @if($c->image)<img src="{{ $c->imageUrl() }}" alt="{{ $c->name }}" loading="lazy" width="120" height="120">@else<span class="bubble-letter">{{ mb_substr($c->name, 0, 1) }}</span>@endif
                    </span>
                    <span class="bubble-name">{{ $c->name }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@include('partials.home-rail', ['title' => 'Bestsellers', 'sub' => 'Loved by our customers', 'list' => $bestsellers, 'more' => url('shop').'/', 'eager' => true])

@if(count($priceTiles))
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>Gifts for every budget</h2><p>Personalised, without the guesswork</p></div></div>
        <div class="price-tiles">
            @foreach($priceTiles as $t)
                <a href="{{ url('shop') }}/?max={{ $t['max'] }}&amp;orderby=price" class="price-tile pt{{ $loop->iteration }}">
                    <small>Gifts under</small><b>{{ money($t['max']) }}</b><span>{{ $t['count'] }} {{ \Illuminate\Support\Str::plural('gift', $t['count']) }} →</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@foreach($spotlights->take(1) as $s)@include('partials.home-spotlight', ['s' => $s, 'n' => 1])@endforeach

@if($deals->isNotEmpty())
<section class="section deals">
    <div class="wrap">
        <div class="deals-box">
            <div class="section-head"><div><span class="eyebrow light">Limited-time prices</span><h2>Offers you’ll love</h2></div><a href="{{ url('shop') }}/?sale=1">All offers</a></div>
            <div class="rail">@foreach($deals as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
        </div>
    </div>
</section>
@endif

<section class="section how" id="how">
    <div class="wrap">
        <div class="center"><span class="eyebrow">Simple as 1-2-3</span><h2>Made for you in three steps</h2></div>
        <ol class="steps steps-cards">
            <li><span class="step-n">1</span>@include('partials.icon', ['i' => 'gift', 'class' => 'step-ico'])<h3>Choose your gift</h3><p>Magnets, mugs, bottles, keychains, badges — pick the size and style.</p></li>
            <li><span class="step-n">2</span>@include('partials.icon', ['i' => 'camera', 'class' => 'step-ico'])<h3>Upload your photos</h3><p>Straight from your phone gallery. Add names, dates or a message.</p></li>
            <li><span class="step-n">3</span>@include('partials.icon', ['i' => 'truck', 'class' => 'step-ico'])<h3>We craft &amp; deliver</h3><p>Made and checked by hand, packed with care and shipped to your door with tracking.</p></li>
        </ol>
        <div class="center"><a class="btn primary" href="{{ url('shop') }}/">Start personalising @include('partials.icon', ['i' => 'arrow'])</a></div>
    </div>
</section>

@foreach($spotlights->slice(1) as $s)@include('partials.home-spotlight', ['s' => $s, 'n' => $loop->iteration + 1])@endforeach

<section class="section">
    <div class="wrap">
        <div class="custom-band">
            <div class="custom-text">
                <span class="eyebrow light">Custom &amp; bulk orders</span>
                <h2>Have an idea of your own?</h2>
                <p>Send us your photo and idea on WhatsApp — birthdays, weddings, return gifts or company merch. We’ll suggest options and a price.</p>
                <div class="cta-actions">
                    @if(config('store.whatsapp'))<a class="btn wa" href="https://wa.me/{{ config('store.whatsapp') }}?text={{ rawurlencode('Hi! I have a custom gift idea: ') }}" target="_blank" rel="noopener">@include('partials.icon', ['i' => 'whatsapp']) Chat on WhatsApp</a>@endif
                    <a class="btn light" href="{{ url('contact-us') }}/">Send a message</a>
                </div>
            </div>
            <div class="custom-art" aria-hidden="true">
                @foreach($featured->concat($bestsellers)->unique('id')->filter(fn ($p) => $p->images->isNotEmpty())->take(3) as $p)
                    <span class="polaroid p{{ $loop->iteration }}">@include('partials.picture', ['path' => $p->images->first()->path, 'alt' => '', 'sizes' => '160px', 'lazy' => true])</span>
                @endforeach
            </div>
        </div>
    </div>
</section>

@if($reviews->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div><h2>Happy customers</h2>@if($rating['count'])<p><span class="stars-sm"><span style="--r: {{ $rating['avg'] }}"></span></span> {{ $rating['avg'] }} out of 5 · {{ $rating['count'] }} {{ \Illuminate\Support\Str::plural('review', $rating['count']) }}</p>@endif</div>
        </div>
        <div class="rail reviews-rail">
            @foreach($reviews as $r)
                <figure class="quote">
                    <div class="stars-sm"><span style="--r: {{ $r->rating }}"></span></div>
                    <blockquote>“{{ \Illuminate\Support\Str::limit($r->body, 180) }}”</blockquote>
                    <figcaption><span class="avatar">{{ mb_strtoupper(mb_substr($r->name, 0, 1)) }}</span><span><b>{{ $r->name }}</b>@if($r->product)<br><a href="{{ $r->product->url() }}">{{ \Illuminate\Support\Str::limit($r->product->name, 40) }}</a>@endif</span></figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
@endif

@include('partials.home-rail', ['title' => 'Just arrived', 'sub' => 'New ways to say it', 'list' => $newest, 'more' => url('shop').'/?orderby=date'])
@include('partials.home-rail', ['title' => 'Handpicked for you', 'sub' => 'Our favourite pieces this season', 'list' => $featured, 'more' => url('shop').'/'])

<section class="section">
    <div class="wrap">
        <ul class="why-grid">
            <li>@include('partials.icon', ['i' => 'hand'])<b>Handmade in {{ config('store.city') }}</b><span>Every piece made to order by our own team.</span></li>
            <li>@include('partials.icon', ['i' => 'sparkle'])<b>Checked before it ships</b><span>We look over your photos and details as we make it.</span></li>
            <li>@include('partials.icon', ['i' => 'truck'])<b>Tracked delivery</b><span>Delhivery to every corner of India, with live tracking.</span></li>
            <li>@include('partials.icon', ['i' => 'return'])<b>Damaged? We fix it</b><span>Free replacement or refund if it arrives broken.</span></li>
        </ul>
    </div>
</section>

<section class="section">
    <div class="wrap narrow">
        <div class="center"><span class="eyebrow">Good to know</span><h2>Questions, answered</h2></div>
        <div class="faq">
            @foreach($faqs as [$q, $a])
                <details @if($loop->first) open @endif><summary>{{ $q }}<span class="chev"></span></summary><p>{{ $a }}</p></details>
            @endforeach
        </div>
        <p class="center muted small">Something else? <a class="link-btn" href="{{ url('contact-us') }}/">Ask us →</a></p>
    </div>
</section>

<section class="section seo-copy">
    <div class="wrap narrow">
        @if($html = setting('home_seo_content'))
            {!! $html !!}
        @else
            <h2>Personalised gifts online in India</h2>
            <p>{{ config('store.name') }} makes custom photo gifts for birthdays, anniversaries, weddings, Valentine's Day, Raksha Bandhan and every moment in between. Upload your photos, add a name or a message, and we make each gift by hand in {{ config('store.city') }} after you order. Choose from personalised photo magnets, custom mugs, bottles, keychains, badges and more, with cash on delivery and tracked shipping across India.</p>
        @endif
    </div>
</section>
@endsection
