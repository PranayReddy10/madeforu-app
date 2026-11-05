@php $c = $s->category; $cover = $c->image ? $c->imageUrl() : $s->products->first()?->thumbUrl(); @endphp
<section class="section spotlight sp{{ $n }} spot-{{ $layout ?? 'rail' }}">
    <div class="wrap">
        <a href="{{ $c->url() }}" class="spot-head">
            @if($cover)<img src="{{ $cover }}" alt="" loading="lazy" width="120" height="120">@endif
            <span>
                <small>{{ $c->products_count }} designs</small>
                <b>{{ $c->name }}</b>
                <em>Personalise yours →</em>
            </span>
        </a>
        @include('partials.product-group', ['list' => $s->products, 'layout' => $layout ?? 'rail'])
    </div>
</section>
