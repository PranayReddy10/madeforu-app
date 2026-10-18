@if($s['data']['categories']->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>{{ $s['title'] }}</h2>@if($s['subtitle'])<p>{{ $s['subtitle'] }}</p>@endif</div><a href="{{ route('categories') }}">See all</a></div>
        @if($s['layout'] === 'grid')
        <div class="cat-grid">
            @foreach($s['data']['categories'] as $c)
                <a href="{{ $c->url() }}" class="cat-tile">
                    <span class="ct-img">@if($c->image)<img src="{{ $c->imageUrl() }}" alt="{{ $c->name }}" loading="lazy" width="160" height="160">@else<span class="bubble-letter">{{ mb_substr($c->name, 0, 1) }}</span>@endif</span>
                    <span class="ct-name">{{ $c->name }}</span>
                </a>
            @endforeach
        </div>
        @else
        <div class="hscroll" data-hscroll>
        <button type="button" class="hs-arrow prev" data-hscroll-prev aria-label="Scroll left">‹</button>
        <div class="cat-bubbles" data-hscroll-row>
            @foreach($s['data']['categories'] as $c)
                <a href="{{ $c->url() }}" class="bubble">
                    <span class="bubble-img">
                        @if($c->image)<img src="{{ $c->imageUrl() }}" alt="{{ $c->name }}" loading="lazy" width="120" height="120">@else<span class="bubble-letter">{{ mb_substr($c->name, 0, 1) }}</span>@endif
                    </span>
                    <span class="bubble-name">{{ $c->name }}</span>
                </a>
            @endforeach
        </div>
        <button type="button" class="hs-arrow next" data-hscroll-next aria-label="Scroll right">›</button>
        </div>
        @endif
    </div>
</section>
@endif
