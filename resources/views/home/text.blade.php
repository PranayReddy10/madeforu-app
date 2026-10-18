@if($s['title'] || trim(strip_tags($s['body'])) !== '')
<section class="section seo-copy">
    <div class="wrap narrow">
        @if($s['title'])<h2>{{ $s['title'] }}</h2>@endif
        {!! $s['body'] !!}
    </div>
</section>
@endif
