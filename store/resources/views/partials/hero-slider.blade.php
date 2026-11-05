{{--
    Home page slider. Admin → Banners drives it; until banners are added, three built-in slides
    made from the catalogue keep it from ever looking empty.
--}}
@php
    $slides = collect();
    if (\App\Support\Campaign::active()) {
        $slides->push(['kind' => 'campaign']);
    }
    foreach ($banners as $b) {
        $slides->push(['kind' => 'banner', 'b' => $b]);
    }
    // Built-in slides (Admin → Banners): shown while there are no banners, or alongside them if chosen.
    $own = $slides->reject(fn ($s) => $s['kind'] === 'campaign')->isNotEmpty();
    if (! $own || setting('hero.with_banners')) {
        $on = fn ($k) => (string) setting('hero.'.$k, '1') !== '0';
        if ($on('brand')) {
            $pics = $featured->concat($bestsellers)->unique('id')->filter(fn ($p) => $p->images->isNotEmpty())->values();
            $slides->push(['kind' => 'brand', 'pics' => $pics->take(3)]);
        }
        if ($on('product') && ($top = $bestsellers->first())) {
            $slides->push(['kind' => 'product', 'p' => $top]);
        }
        if ($on('offer')) {
            $slides->push(['kind' => 'offer']);
        }
    }
    if ($slides->isEmpty()) {   // never an empty hero
        $slides->push(['kind' => 'brand', 'pics' => $featured->concat($bestsellers)->unique('id')->filter(fn ($p) => $p->images->isNotEmpty())->take(3)->values()]);
    }
@endphp
<section class="hero-slider" data-slider aria-roledescription="carousel" aria-label="Featured">
    <div class="hs-track" data-slider-track>
        @foreach($slides as $i => $s)
            <div class="hs-slide hs-{{ $s['kind'] }}" role="group" aria-roledescription="slide" aria-label="{{ $i + 1 }} of {{ $slides->count() }}">
                @if($s['kind'] === 'campaign')
                    @php $C = \App\Support\Campaign::class; @endphp
                    <a href="{{ $C::url() }}" class="hs-inner hs-camp">
                        <div class="hs-copy">
                            <span class="hs-kicker">🪔 {{ $C::name() }} gifts</span>
                            <h2 class="hs-title">{{ $C::headline() }}</h2>
                            <p class="hs-sub">@if($C::beforeDeadline())Order by {{ $C::deadline()->format('D, j M') }} for delivery before {{ $C::name() }}@else Last-minute? Message us on WhatsApp @endif @if($C::coupon() && $C::discount() > 0)· use <b>{{ $C::coupon() }}</b> for {{ rtrim(rtrim(number_format($C::discount(), 1), '0'), '.') }}% off @endif</p>
                            <span class="btn light">Shop {{ $C::name() }} gifts</span>
                        </div>
                        <div class="hs-art camp-art" aria-hidden="true"><span>🪔</span><span>✨</span><span>🎁</span></div>
                    </a>
                @elseif($s['kind'] === 'banner')
                    @php $b = $s['b']; @endphp
                    <a href="{{ $b->link ?: url('shop').'/' }}" class="hs-banner">
                        <picture>
                            @if($b->mobile_image)<source media="(max-width: 767px)" srcset="{{ media_url($b->mobile_image) }}">@endif
                            <img src="{{ media_url($b->image) }}" alt="{{ $b->title }}" width="1920" height="720" @if($i) loading="lazy" @else fetchpriority="high" @endif>
                        </picture>
                        @if($b->show_text)
                            <span class="hs-overlay">
                                <span class="hs-copy">
                                    <b class="hs-title">{{ $b->title }}</b>
                                    @if($b->subtitle)<span class="hs-sub">{{ $b->subtitle }}</span>@endif
                                    <span class="btn primary">{{ $b->button ?: 'Shop now' }}</span>
                                </span>
                            </span>
                        @endif
                    </a>
                @elseif($s['kind'] === 'brand')
                    <div class="hs-inner">
                        <div class="hs-copy">
                            <span class="hs-kicker">♥ Made for your moments</span>
                            <h1 class="hs-title">Your photos, turned into gifts they’ll treasure</h1>
                            <p class="hs-sub">Choose a gift, upload your pictures, and we hand-craft it for you.</p>
                            <a href="{{ url('shop') }}/" class="btn light">Start personalising</a>
                        </div>
                        <div class="hs-art polaroids" aria-hidden="true">
                            @forelse($s['pics'] as $k => $p)
                                <span class="polaroid p{{ $k }}"><img src="{{ $p->thumbUrl() }}" alt="" width="300" height="300" @if($k) loading="lazy" @else fetchpriority="high" @endif></span>
                            @empty
                                <img src="{{ asset('images/logo-round.png') }}" alt="" class="hs-logo-art" width="300" height="307">
                            @endforelse
                        </div>
                    </div>
                @elseif($s['kind'] === 'product')
                    @php $p = $s['p']; $off = $p->discountPercent(); @endphp
                    <a href="{{ $p->url() }}" class="hs-inner">
                        <div class="hs-copy">
                            <span class="hs-kicker">★ Bestseller</span>
                            <b class="hs-title">{{ $p->name }}</b>
                            <span class="hs-price">{{ money($p->currentPrice()) }} @if($off)<s>{{ money($p->regularPrice()) }}</s> <em>{{ $off }}% off</em>@endif</span>
                            <span class="btn primary">Personalise now</span>
                        </div>
                        <div class="hs-art">
                            <span class="hs-blob"><img src="{{ $p->thumbUrl() }}" alt="{{ $p->name }}" width="420" height="420" loading="lazy"></span>
                        </div>
                    </a>
                @else
                    <div class="hs-inner">
                        <div class="hs-copy">
                            <span class="hs-kicker">Why MadeForU</span>
                            <b class="hs-title">Free shipping over {{ money(setting('free_shipping_over')) }}</b>
                            <ul class="hs-points">
                                <li>Free design preview on WhatsApp</li>
                                @if(setting('cod_enabled'))<li>Cash on delivery available</li>@endif
                                <li>Hand-made and delivered across India</li>
                            </ul>
                            <a href="{{ route('categories') }}" class="btn primary">Browse categories</a>
                        </div>
                        <div class="hs-art">
                            <img src="{{ asset('images/logo-round.png') }}" alt="" class="hs-logo-art" width="300" height="307" loading="lazy">
                        </div>
                    </div>
                @endif
            </div>
        @endforeach
    </div>
    @if($slides->count() > 1)
        <button type="button" class="hs-nav prev" data-slider-prev aria-label="Previous slide"><svg viewBox="0 0 24 24"><path d="m15 18-6-6 6-6"/></svg></button>
        <button type="button" class="hs-nav next" data-slider-next aria-label="Next slide"><svg viewBox="0 0 24 24"><path d="m9 18 6-6-6-6"/></svg></button>
        <div class="hs-dots" role="tablist">
            @foreach($slides as $i => $s)<button type="button" data-slider-dot="{{ $i }}" aria-label="Go to slide {{ $i + 1 }}"><span></span></button>@endforeach
        </div>
    @endif
</section>
