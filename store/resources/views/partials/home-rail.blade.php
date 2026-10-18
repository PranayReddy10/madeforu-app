@if($list->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>{{ $title }}</h2>@if(! empty($sub))<p>{{ $sub }}</p>@endif</div><a href="{{ $more }}">View all</a></div>
        @include('partials.product-group', ['list' => $list, 'layout' => $layout ?? 'rail', 'eager' => $eager ?? false])
    </div>
</section>
@endif
