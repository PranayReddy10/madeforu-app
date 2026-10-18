{{-- Serves the WebP copy made at import time when the browser supports it --}}
@php
    $isRemote = preg_match('#^https?://#', $path);
    $webp = !$isRemote ? preg_replace('/\.(jpe?g|png)$/i', '.webp', $path) : null;
    // WebP copies are only made for images on this server; checking a remote disk per image would be slow.
    $hasWebp = $webp && $webp !== $path && config('store.media_disk') === 'public' && \Illuminate\Support\Facades\Storage::disk('public')->exists($webp);
@endphp
<picture>
    @if($hasWebp)<source srcset="{{ media_url($webp) }}" type="image/webp">@endif
    <img src="{{ media_url($path) }}" alt="{{ $alt }}" @if($lazy ?? true) loading="lazy" decoding="async" @else fetchpriority="high" @endif width="{{ $w ?? 600 }}" height="{{ $h ?? 600 }}" @isset($sizes) sizes="{{ $sizes }}" @endisset @isset($attrs) {!! $attrs !!} @endisset>
</picture>
