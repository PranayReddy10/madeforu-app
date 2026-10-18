<!doctype html>
<html lang="en-IN">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <title>{{ $seo->fullTitle() }}</title>
    @if($seo->description)<meta name="description" content="{{ $seo->description }}">@endif
    @if($seo->keywords)<meta name="keywords" content="{{ $seo->keywords }}">@endif
    <meta name="robots" content="{{ $seo->noindex ? 'noindex, follow' : 'index, follow, max-image-preview:large, max-snippet:-1, max-video-preview:-1' }}">
    <link rel="canonical" href="{{ $seo->canonical ?: url()->current() }}">
    @if($seo->prev)<link rel="prev" href="{{ $seo->prev }}">@endif
    @if($seo->next)<link rel="next" href="{{ $seo->next }}">@endif
    <meta property="og:site_name" content="{{ config('store.name') }}">
    <meta property="og:locale" content="en_IN">
    <meta property="og:type" content="{{ $seo->type }}">
    <meta property="og:title" content="{{ $seo->title ?: config('store.name') }}">
    @if($seo->description)<meta property="og:description" content="{{ $seo->description }}">@endif
    <meta property="og:url" content="{{ $seo->canonical ?: url()->current() }}">
    <meta property="og:image" content="{{ $seo->image ?: asset('images/og-default.png') }}">
    @foreach($seo->og as $k => $v)<meta property="{{ $k }}" content="{{ $v }}">
    @endforeach
    <meta name="twitter:card" content="summary_large_image">
    <meta name="theme-color" content="#ffffff">
    <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="{{ config('store.name') }}">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    @foreach(['google_verification' => 'google-site-verification', 'bing_verification' => 'msvalidate.01', 'pinterest_verification' => 'p:domain_verify', 'facebook_domain_verification' => 'facebook-domain-verification'] as $key => $name)
        @if($v = setting('analytics.'.$key))<meta name="{{ $name }}" content="{{ $v }}">@endif
    @endforeach
    <link rel="manifest" href="{{ route('manifest') }}">
    <link rel="icon" href="{{ asset('favicon.ico') }}" sizes="48x48">
    <link rel="icon" href="{{ asset('images/icon-192.png') }}" type="image/png" sizes="192x192">
    <link rel="apple-touch-icon" href="{{ asset('images/apple-touch-icon.png') }}">
    <link rel="preload" as="image" href="{{ asset('images/logo.webp') }}" type="image/webp">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Fraunces:opsz,wght@9..144,600;9..144,700&family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/store.css') }}?v={{ @filemtime(public_path('css/store.css')) }}">
    @foreach(collect($seo->schema)->concat(request()->routeIs('home') ? [] : array_slice(app(\App\Services\Seo::class)->siteSchema(), 0, 1)) as $schema)
    <script type="application/ld+json">{!! json_encode($schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}</script>
    @endforeach
    @include('partials.analytics-head')
    @stack('head')
</head>
<body class="@yield('body_class')">
@include('partials.analytics-body')
<a class="skip" href="#main">Skip to content</a>

@if($announcement = setting('announcement'))
    <div class="announce">{{ $announcement }}</div>
@endif

<header class="site-header" id="top">
    <div class="wrap header-row">
        <button class="icon-btn only-mobile" data-open="drawer" aria-label="Open menu" aria-controls="drawer" aria-expanded="false">
            <svg viewBox="0 0 24 24"><path d="M4 7h16M4 12h16M4 17h10"/></svg>
        </button>
        <a href="{{ url('/') }}" class="logo" aria-label="{{ config('store.name') }} home">
            @include('partials.logo', ['eager' => true])
        </a>
        <form class="search-box" action="{{ route('search') }}" method="get" role="search" data-suggest="{{ route('search.suggest') }}">
            <svg viewBox="0 0 24 24" class="search-ico"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" value="{{ request('q') }}" placeholder="Search frames, mugs, lamps…" autocomplete="off" aria-label="Search products">
            <div class="suggest" hidden></div>
        </form>
        <nav class="header-actions">
            <a href="{{ route('search') }}" class="icon-btn only-mobile" aria-label="Search">
                <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            </a>
            <a href="{{ route('track') }}" class="hdr-link hide-mobile"><svg viewBox="0 0 24 24"><path d="M3 7h11v9H3zM14 10h4l3 3v3h-7z"/><circle cx="7" cy="17.5" r="1.8"/><circle cx="17" cy="17.5" r="1.8"/></svg><span>Track order</span></a>
            <a href="{{ route('account') }}" class="icon-btn hide-mobile" aria-label="My account">
                <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg>
            </a>
            <a href="{{ route('cart') }}" class="icon-btn cart-btn" aria-label="Cart">
                <svg viewBox="0 0 24 24"><path d="M5 7h14l-1.2 10.2A2 2 0 0 1 15.8 19H8.2a2 2 0 0 1-2-1.8z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
                <span class="badge" data-cart-count @if(!$cartCount) hidden @endif>{{ $cartCount }}</span>
            </a>
        </nav>
    </div>
    {{-- One menu row: fixed with the header, the categories scroll sideways inside it (phones swipe, desktop shows dropdowns) --}}
    @unless(request()->routeIs('checkout', 'checkout.*', 'cart'))
    <nav class="cat-nav" aria-label="Categories">
        <div class="wrap cat-nav-track" data-scroll-active>
            <a href="{{ url('shop') }}/" class="{{ request()->routeIs('shop') ? 'on' : '' }}">All gifts</a>
            @foreach($menuCategories->reject(fn ($c) => $c->show_in_menu === false) as $cat)
                <div class="cat-nav-item">
                    <a href="{{ $cat->url() }}" class="{{ request()->is('product-category/'.$cat->path, 'product-category/'.$cat->path.'/*') ? 'on' : '' }}">{{ $cat->name }}</a>
                    @if($cat->children->isNotEmpty())
                        <div class="cat-nav-drop">
                            @foreach($cat->children as $child)<a href="{{ $child->url() }}">{{ $child->name }}</a>@endforeach
                        </div>
                    @endif
                </div>
            @endforeach
            {{-- Desktop: categories that don't fit on one line move in here (store.js). --}}
            <div class="cat-nav-item cat-nav-more" data-nav-more hidden>
                <button type="button" aria-haspopup="true">More <span class="chev"></span></button>
                <div class="cat-nav-drop" data-nav-more-list></div>
            </div>
        </div>
    </nav>
    @endunless
</header>

<aside class="drawer" id="drawer" aria-hidden="true" aria-label="Menu">
    <div class="drawer-scrim" data-close="drawer"></div>
    <div class="drawer-panel" role="dialog" aria-modal="true">
        <div class="drawer-head">
            <a href="{{ url('/') }}" class="logo">@include('partials.logo')</a>
            <button class="icon-btn" data-close="drawer" aria-label="Close menu"><svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6 6 18"/></svg></button>
        </div>
        <form class="drawer-search" action="{{ route('search') }}" method="get" role="search">
            <svg viewBox="0 0 24 24" class="search-ico"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg>
            <input type="search" name="q" placeholder="Search gifts" aria-label="Search products">
        </form>
        {{-- Only this part scrolls; the head and the contact bar stay put --}}
        <div class="drawer-body">
            <p class="drawer-label">Shop by category</p>
            <a href="{{ url('shop') }}/" class="drawer-link"><span class="d-ico">✦</span>All gifts</a>
            @foreach($menuCategories as $cat)
                @if($cat->children->isNotEmpty())
                    <details class="drawer-group">
                        <summary>
                            <span class="d-ico">@if($cat->image)<img src="{{ $cat->imageUrl() }}" alt="" width="32" height="32" loading="lazy">@else{{ mb_substr($cat->name, 0, 1) }}@endif</span>
                            {{ $cat->name }}<span class="chev"></span>
                        </summary>
                        <a href="{{ $cat->url() }}" class="drawer-sub">All {{ $cat->name }}</a>
                        @foreach($cat->children as $child)<a href="{{ $child->url() }}" class="drawer-sub">{{ $child->name }}</a>@endforeach
                    </details>
                @else
                    <a href="{{ $cat->url() }}" class="drawer-link">
                        <span class="d-ico">@if($cat->image)<img src="{{ $cat->imageUrl() }}" alt="" width="32" height="32" loading="lazy">@else{{ mb_substr($cat->name, 0, 1) }}@endif</span>{{ $cat->name }}
                    </a>
                @endif
            @endforeach
            <p class="drawer-label">Help</p>
            <a href="{{ route('track') }}" class="drawer-link"><span class="d-ico">🚚</span>Track your order</a>
            <a href="{{ route('account') }}" class="drawer-link"><span class="d-ico">👤</span>My account</a>
            <a href="{{ url('contact-us') }}/" class="drawer-link"><span class="d-ico">✉</span>Contact us</a>
        </div>
        <div class="drawer-foot">
            @if(config('store.whatsapp'))<a href="https://wa.me/{{ config('store.whatsapp') }}" class="btn wa" rel="noopener">WhatsApp us</a>@endif
            @if(config('store.phone'))<a href="tel:{{ config('store.phone') }}" class="btn ghost">Call</a>@endif
            @if(!config('store.whatsapp') && !config('store.phone'))<a href="mailto:{{ config('store.email') }}" class="btn ghost">{{ config('store.email') }}</a>@endif
        </div>
    </div>
</aside>

<main id="main">
    @include('partials.flash')
    @yield('content')
</main>

<footer class="site-footer">
    <div class="wrap footer-grid">
        <div>
            <a href="{{ url('/') }}" class="footer-logo">@include('partials.logo')</a>
            <p>{{ config('store.tagline') }}. Every piece is printed and finished by hand after you order, from your own photos.</p>
            <div class="social">
                @foreach(config('store.social') as $net => $link)<a href="{{ $link }}" rel="noopener" target="_blank">{{ ucfirst($net) }}</a>@endforeach
            </div>
        </div>
        <div>
            <h3>Shop</h3>
            @foreach($menuCategories->take(6) as $cat)<a href="{{ $cat->url() }}">{{ $cat->name }}</a>@endforeach
        </div>
        <div>
            <h3>Help</h3>
            <a href="{{ route('track') }}">Track order</a>
            <a href="{{ route('account') }}">My account</a>
            @foreach($footerPages as $fp)
                <a href="{{ $fp['url'] }}">{{ $fp['title'] }}</a>
            @endforeach
        </div>
        <div>
            <h3>Get in touch</h3>
            @if(config('store.phone'))<a href="tel:{{ config('store.phone') }}">{{ config('store.phone') }}</a>@endif
            <a href="mailto:{{ config('store.email') }}">{{ config('store.email') }}</a>
            <p class="muted">{{ config('store.address') }}</p>
            <p class="pay-icons" aria-label="Payment methods">UPI · Cards · Netbanking · Cash on delivery</p>
        </div>
    </div>
    <div class="wrap footer-bottom">© {{ date('Y') }} {{ config('store.name') }}. Made with love in India.</div>
</footer>

{{-- App-style bottom bar for phones --}}
<nav class="tabbar" aria-label="Quick navigation">
    <a href="{{ url('/') }}" class="{{ request()->routeIs('home') ? 'on' : '' }}">
        <svg viewBox="0 0 24 24"><path d="M3 11 12 4l9 7"/><path d="M5 10v10h14V10"/></svg><span>Home</span>
    </a>
    <a href="{{ route('categories') }}" class="{{ request()->routeIs('categories', 'category', 'shop') ? 'on' : '' }}">
        <svg viewBox="0 0 24 24"><rect x="4" y="4" width="7" height="7" rx="2"/><rect x="13" y="4" width="7" height="7" rx="2"/><rect x="4" y="13" width="7" height="7" rx="2"/><rect x="13" y="13" width="7" height="7" rx="2"/></svg><span>Categories</span>
    </a>
    <a href="{{ route('search') }}" class="{{ request()->routeIs('search') ? 'on' : '' }}" data-open-search>
        <svg viewBox="0 0 24 24"><circle cx="11" cy="11" r="7"/><path d="m20 20-3.5-3.5"/></svg><span>Search</span>
    </a>
    <a href="{{ route('cart') }}" class="{{ request()->routeIs('cart', 'checkout') ? 'on' : '' }}">
        <svg viewBox="0 0 24 24"><path d="M5 7h14l-1.2 10.2A2 2 0 0 1 15.8 19H8.2a2 2 0 0 1-2-1.8z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg>
        <span class="badge" data-cart-count @if(!$cartCount) hidden @endif>{{ $cartCount }}</span><span>Cart</span>
    </a>
    <a href="{{ route('account') }}" class="{{ request()->routeIs('account*', 'login', 'register', 'wishlist', 'track') ? 'on' : '' }}">
        <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="4"/><path d="M4 21c1.5-4 4.5-6 8-6s6.5 2 8 6"/></svg><span>Account</span>
    </a>
</nav>

@if(config('store.whatsapp'))
    {{-- On forms (sign in, checkout…) the floating button sits on top of fields and buttons on phones, so it hides there. --}}
    <a class="wa-float @if(request()->routeIs('login', 'register', 'password.*', 'checkout', 'track', 'account', 'account.*', 'review.order')) wa-float-desktop @endif" href="https://wa.me/{{ config('store.whatsapp') }}?text={{ rawurlencode('Hi! I have a question about '.(isset($product) ? $product->name : 'an order')) }}" rel="noopener" aria-label="Chat on WhatsApp">
        <svg viewBox="0 0 24 24"><path d="M4 20l1.3-3.9A8 8 0 1 1 8 19z"/><path d="M9 9.5c0 3 2.5 5.5 5.5 5.5l1-1.5-2-1-1 .8a4 4 0 0 1-2-2l.8-1-1-2z" fill="currentColor" stroke="none"/></svg>
    </a>
@endif

<div class="toast" role="status" aria-live="polite" hidden></div>
@if($analytics->events)<script>@foreach($analytics->events as $e) mfuTrack({!! json_encode($e, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) !!}); @endforeach</script>@endif
<script src="{{ asset('js/store.js') }}?v={{ @filemtime(public_path('js/store.js')) }}" defer></script>
@stack('scripts')
</body>
</html>
