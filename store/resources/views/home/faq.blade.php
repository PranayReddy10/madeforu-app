@if($s['items'])
<section class="section">
    <div class="wrap narrow">
        <div class="center">@if($s['eyebrow'])<span class="eyebrow">{{ $s['eyebrow'] }}</span>@endif<h2>{{ $s['title'] }}</h2></div>
        <div class="faq">
            @foreach($s['items'] as $f)
                <details @if($loop->first) open @endif><summary>{{ $f['q'] }}<span class="chev"></span></summary><p>{{ $f['a'] }}</p></details>
            @endforeach
        </div>
        <p class="center muted small">Something else? <a class="link-btn" href="{{ url('contact-us') }}/">Ask us →</a></p>
    </div>
</section>
@endif
