@if($s['data']['products']->isNotEmpty())
<section class="section deals">
    <div class="wrap">
        <div class="deals-box">
            <div class="section-head"><div>@if($s['eyebrow'])<span class="eyebrow light">{{ $s['eyebrow'] }}</span>@endif<h2>{{ $s['title'] }}</h2></div><a href="{{ url('shop') }}/?sale=1">All offers</a></div>
            <div class="rail">@foreach($s['data']['products'] as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
        </div>
    </div>
</section>
@endif
