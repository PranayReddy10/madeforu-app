@php $c = $s->category; $cover = $c->image ? $c->imageUrl() : $s->products->first()?->mainImageUrl(); @endphp
<section class="section spotlight sp{{ $n }}">
    <div class="wrap">
        <a href="{{ $c->url() }}" class="spot-head">
            @if($cover)<img src="{{ $cover }}" alt="" loading="lazy" width="120" height="120">@endif
            <span>
                <small>{{ $c->products_count }} designs</small>
                <b>{{ $c->name }}</b>
                <em>Personalise yours →</em>
            </span>
        </a>
        <div class="rail">@foreach($s->products as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
    </div>
</section>
