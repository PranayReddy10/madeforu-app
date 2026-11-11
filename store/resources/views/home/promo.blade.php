@if($s['image'])
<section class="section">
    <div class="wrap">
        <a href="{{ $s['link'] ?: url('shop').'/' }}" class="promo {{ ($s['title'] || $s['text']) ? 'has-text' : '' }}">
            <picture>
                @if($s['mobile_image'])<source media="(max-width: 767px)" srcset="{{ media_url($s['mobile_image']) }}">@endif
                <img src="{{ media_url($s['image']) }}" alt="{{ $s['title'] ?: config('store.name') }}" loading="lazy" width="1600" height="500">
            </picture>
            @if($s['title'] || $s['text'])
                <span class="promo-text">
                    @if($s['title'])<b>{{ $s['title'] }}</b>@endif
                    @if($s['text'])<span>{{ $s['text'] }}</span>@endif
                    @if($s['button'])<em class="btn light small">{{ $s['button'] }} →</em>@endif
                </span>
            @endif
        </a>
    </div>
</section>
@endif
