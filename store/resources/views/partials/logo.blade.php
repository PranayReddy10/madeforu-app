<picture>
    <source srcset="{{ asset('images/logo.webp') }}" type="image/webp">
    <img src="{{ asset('images/logo.png') }}" alt="{{ config('store.name') }} - Made for your moments" width="612" height="160" @if($eager ?? false) fetchpriority="high" @else loading="lazy" @endif>
</picture>
