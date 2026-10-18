@if($s['data']['reviews']->isNotEmpty())
<section class="section">
    <div class="wrap">
        <div class="section-head">
            <div><h2>{{ $s['title'] }}</h2>@if($rating['count'])<p><span class="stars-sm"><span style="--r: {{ $rating['avg'] }}"></span></span> {{ $rating['avg'] }} out of 5 · {{ $rating['count'] }} {{ \Illuminate\Support\Str::plural('review', $rating['count']) }}</p>@endif</div>
        </div>
        <div class="rail reviews-rail">
            @foreach($s['data']['reviews'] as $r)
                <figure class="quote">
                    <div class="stars-sm"><span style="--r: {{ $r->rating }}"></span></div>
                    <blockquote>“{{ \Illuminate\Support\Str::limit($r->body, 180) }}”</blockquote>
                    <figcaption><span class="avatar">{{ mb_strtoupper(mb_substr($r->name, 0, 1)) }}</span><span><b>{{ $r->name }}</b>@if($r->product)<br><a href="{{ $r->product->url() }}">{{ \Illuminate\Support\Str::limit($r->product->name, 40) }}</a>@endif</span></figcaption>
                </figure>
            @endforeach
        </div>
    </div>
</section>
@endif
