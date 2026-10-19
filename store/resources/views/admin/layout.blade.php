<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Admin') · {{ config('store.name') }} Admin</title>
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="{{ asset('css/admin.css') }}?v={{ @filemtime(public_path('css/admin.css')) }}">
</head>
<body>
<div class="adm">
    <aside class="adm-side" data-adm-side>
        <div class="adm-bar">
            <a class="adm-brand" href="{{ route('admin.dashboard') }}">{{ config('store.name') }} <small>admin</small></a>
            <button type="button" class="adm-menu-btn" data-adm-menu aria-expanded="false" aria-controls="adm-nav"><span class="adm-menu-ico" aria-hidden="true"><i></i><i></i><i></i></span> Menu</button>
        </div>
        <nav class="adm-nav" id="adm-nav">
            @php
                $nav = [
                    ['admin.dashboard', 'Dashboard', 'admin'],
                    ['admin.analytics', 'Analytics', 'admin/analytics*'],
                    ['admin.profit', 'Profit', 'admin/profit*'],
                    ['admin.orders.index', 'Orders', 'admin/orders*'],
                    ['admin.users.index', 'Customers', 'admin/customers*'],
                    ['admin.products.index', 'Products', 'admin/products*'],
                    ['admin.categories.index', 'Categories', 'admin/categories*'],
                    ['admin.media.index', 'Media', 'admin/media*'],
                    ['admin.abandoned.index', 'Abandoned carts', 'admin/abandoned-carts*'],
                    ['admin.reviews.index', 'Reviews', 'admin/reviews*'],
                    ['admin.messages.index', 'Messages', 'admin/messages*'],
                    ['admin.coupons.index', 'Coupons', 'admin/coupons*'],
                    ['admin.home.edit', 'Home page', 'admin/home-page*'],
                    ['admin.banners.index', 'Banners', 'admin/banners*'],
                    ['admin.pages.index', 'Pages', 'admin/pages*'],
                    ['admin.redirects.index', 'Redirects', 'admin/redirects*'],
                    ['admin.seo', 'SEO', 'admin/seo*'],
                    ['admin.import', 'Import', 'admin/import*'],
                    ['admin.settings', 'Settings', 'admin/settings*'],
                ];
            @endphp
            @foreach ($nav as [$route, $label, $pattern])
                <a href="{{ route($route) }}" class="{{ request()->is($pattern) ? 'active' : '' }}">{{ $label }}</a>
            @endforeach
            <a href="{{ url('/') }}" target="_blank" rel="noopener">View store ↗</a>
        </nav>
        <form method="post" action="{{ route('logout') }}" class="adm-logout">@csrf<button type="submit" class="link">Log out</button></form>
    </aside>
    <main class="adm-main">
        <header class="adm-head">
            <h1>@yield('title', 'Admin')</h1>
            <div class="adm-actions">@yield('actions')</div>
        </header>
        @if (session('status'))<div class="flash ok">{{ session('status') }}</div>@endif
        @if (session('error'))<div class="flash err">{{ session('error') }}</div>@endif
        @if ($errors->any())
            <div class="flash err"><strong>Please fix the following:</strong>
                <ul>@foreach ($errors->all() as $e)<li>{{ $e }}</li>@endforeach</ul>
            </div>
        @endif
        @yield('content')
    </main>
</div>
@include('admin.partials.media-picker')
<script src="{{ asset('js/admin.js') }}?v={{ @filemtime(public_path('js/admin.js')) }}"></script>
</body>
</html>
