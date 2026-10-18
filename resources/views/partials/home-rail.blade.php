@if($list->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>{{ $title }}</h2>@if(! empty($sub))<p>{{ $sub }}</p>@endif</div><a href="{{ $more }}">View all</a></div>
        <div class="rail">
            @foreach($list as $p)@include('partials.product-card', ['p' => $p, 'eager' => ($eager ?? false) && $loop->index < 2])@endforeach
        </div>
    </div>
</section>
@endif
