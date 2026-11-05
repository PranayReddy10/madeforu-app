@if(count($s['data']['tiles']))
<section class="section">
    <div class="wrap">
        <div class="section-head"><div><h2>{{ $s['title'] }}</h2>@if($s['subtitle'])<p>{{ $s['subtitle'] }}</p>@endif</div></div>
        <div class="price-tiles">
            @foreach($s['data']['tiles'] as $t)
                <a href="{{ url('shop') }}/?max={{ $t['max'] }}&amp;orderby=price" class="price-tile pt{{ ($loop->index % 4) + 1 }}">
                    <small>Gifts under</small><b>{{ money($t['max']) }}</b><span>{{ $t['count'] }} {{ \Illuminate\Support\Str::plural('gift', $t['count']) }} →</span>
                </a>
            @endforeach
        </div>
    </div>
</section>
@endif
