@extends('layouts.app')
@section('body_class', 'page-home')
@section('content')

{{-- Phone: an app-style home — search, round category shortcuts, banners, rails --}}
<section class="hero">
    <div class="wrap hero-grid">
        <div class="hero-copy">
            <p class="eyebrow">Personalised gifts · Made to order</p>
            <h1>Your photos, turned into gifts they'll keep forever.</h1>
            <p class="lead">Pick a gift, upload your favourite pictures, and we hand-craft it for you. Delivered anywhere in India.</p>
            <div class="hero-cta">
                <a href="{{ url('shop') }}/" class="btn primary">Start personalising</a>
                <a href="#how" class="btn ghost">How it works</a>
            </div>
            <ul class="trust">
                <li><b>Free</b> design preview</li>
                <li><b>Free</b> shipping over {{ money(setting('free_shipping_over')) }}</li>
                <li><b>COD</b> available</li>
            </ul>
        </div>
        <div class="hero-art" aria-hidden="true">
            @foreach($featured->take(3) as $i => $p)
                <a href="{{ $p->url() }}" class="polaroid p{{ $i }}" tabindex="-1">
                    <img src="{{ $p->mainImageUrl() }}" alt="" @if($i) loading="lazy" @else fetchpriority="high" @endif width="300" height="300">
                    <span>{{ \Illuminate\Support\Str::limit($p->name, 26) }}</span>
                </a>
            @endforeach
        </div>
    </div>
</section>

@if($categories->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><h2>Shop by category</h2><a href="{{ route('categories') }}">See all</a></div>
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

@if($banners->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="banners" data-carousel>
            @foreach($banners as $b)
                <a href="{{ $b->link ?: url('shop').'/' }}" class="banner">
                    <img src="{{ media_url($b->image) }}" alt="{{ $b->title }}" @if(!$loop->first) loading="lazy" @endif width="1200" height="480">
                    <span class="banner-copy"><b>{{ $b->title }}</b>@if($b->subtitle)<span>{{ $b->subtitle }}</span>@endif @if($b->button)<em>{{ $b->button }} →</em>@endif</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif

@foreach([['Bestsellers', 'Loved by our customers', $bestsellers], ['Handpicked for you', 'Our favourite pieces this season', $featured], ['Just arrived', 'New ways to say it', $newest]] as [$title, $sub, $list])
    @if($list->isNotEmpty())
    <section class="section">
        <div class="wrap">
            <div class="section-head"><div><h2>{{ $title }}</h2><p>{{ $sub }}</p></div><a href="{{ url('shop') }}/">View all</a></div>
            <div class="rail">
                @foreach($list as $p)@include('partials.product-card', ['p' => $p])@endforeach
            </div>
        </div>
    </section>
    @endif
@endforeach

<section class="section how" id="how">
    <div class="wrap">
        <h2 class="center">Made for you in three steps</h2>
        <ol class="steps">
            <li><span class="step-n">1</span><h3>Choose your gift</h3><p>Frames, mugs, lamps, cushions, keychains — pick the size and style.</p></li>
            <li><span class="step-n">2</span><h3>Upload your photos</h3><p>Straight from your phone gallery. Add names, dates or a message.</p></li>
            <li><span class="step-n">3</span><h3>We craft &amp; deliver</h3><p>Printed and finished by hand, checked, packed with care and shipped to your door.</p></li>
        </ol>
    </div>
</section>

@if($reviews->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><h2>Happy customers</h2></div>
        <div class="rail reviews-rail">
            @foreach($reviews as $r)
                <figure class="quote">
                    <div class="stars-sm"><span style="--r: {{ $r->rating }}"></span></div>
                    <blockquote>{{ \Illuminate\Support\Str::limit($r->body, 180) }}</blockquote>
                    <figcaption><b>{{ $r->name }}</b>@if($r->product) on <a href="{{ $r->product->url() }}">{{ $r->product->name }}</a>@endif</figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
@endif

<section class="section seo-copy">
    <div class="wrap narrow">
        @if($html = setting('home_seo_content'))
            {!! $html !!}
        @else
            <h2>Personalised gifts online in India</h2>
            <p>{{ config('store.name') }} makes custom photo gifts for birthdays, anniversaries, weddings, Valentine's Day, Raksha Bandhan and every moment in between. Upload your photos, add a name or a message, and we print and finish each gift by hand after you order. Choose from personalised photo frames, custom mugs, LED photo lamps, cushions, keychains and more, with cash on delivery and fast shipping across India.</p>
        @endif
    </div>
</section>
@endsection
