@php $off = $p->discountPercent(); $img = $p->images->first(); @endphp
<article class="card {{ ! empty($big) ? 'card-big' : '' }}">
    <a href="{{ $p->url() }}" class="card-img">
        @if($img)
            @include('partials.picture', ['path' => $img->path, 'alt' => $img->alt ?: $p->name, 'sizes' => '(max-width: 640px) 50vw, 260px', 'lazy' => !($eager ?? false)])
        @else
            <img src="{{ asset('images/placeholder.svg') }}" alt="{{ $p->name }}" loading="lazy" width="400" height="400">
        @endif
        @if($off)<span class="tag sale">{{ $off }}% off</span>@endif
        @if($p->needsUpload())<span class="tag photo" title="Personalise with your photos">📷 {{ $p->uploadCount() > 1 ? $p->uploadCount().' photos' : 'Your photo' }}</span>@endif
    </a>
    <div class="card-body">
        <a href="{{ $p->url() }}" class="card-title">{{ $p->name }}</a>
        @if($p->rating_count)<div class="stars-sm" aria-label="Rated {{ $p->rating_avg }} out of 5"><span style="--r: {{ $p->rating_avg }}"></span> <small>({{ $p->rating_count }})</small></div>@endif
        <div class="price">
            @if($p->type === 'variable' && $p->maxPrice() > $p->currentPrice())<small>From</small>@endif
            <b>{{ money($p->currentPrice()) }}</b>
            @if($off)<s>{{ money($p->regularPrice()) }}</s>@endif
        </div>
    </div>
</article>
