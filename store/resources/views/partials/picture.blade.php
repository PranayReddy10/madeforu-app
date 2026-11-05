{{-- Serves the WebP copy made at import time when the browser supports it --}}
@php
    $isRemote = preg_match('#^https?://#', $path);
    $webp = !$isRemote ? preg_replace('/\.(jpe?g|png)$/i', '.webp', $path) : null;
    // WebP copies are only made for images on this server; checking a remote disk per image would be slow.
    $local = $webp && config('store.media_disk') === 'public';
    $hasWebp = $local && $webp !== $path && \Illuminate\Support\Facades\Storage::disk('public')->exists($webp);
    // A 480 px copy for cards and rails; the browser picks it or the full one from "sizes".
    $thumb = $local ? \App\Services\ImageTools::thumbPath($path) : null;
    $hasThumb = $thumb && \Illuminate\Support\Facades\Storage::disk('public')->exists($thumb);
@endphp
<picture>
    @if($hasThumb)<source srcset="{{ media_url($thumb) }} {{ \App\Services\ImageTools::THUMB }}w{{ $hasWebp ? ', '.media_url($webp).' 1600w' : '' }}" @isset($sizes) sizes="{{ $sizes }}" @endisset type="image/webp">
    @elseif($hasWebp)<source srcset="{{ media_url($webp) }}" type="image/webp">@endif
    <img src="{{ media_url($path) }}" alt="{{ $alt }}" @if($lazy ?? true) loading="lazy" decoding="async" @else fetchpriority="high" @endif width="{{ $w ?? 600 }}" height="{{ $h ?? 600 }}" @isset($sizes) sizes="{{ $sizes }}" @endisset @isset($attrs) {!! $attrs !!} @endisset>
</picture>
