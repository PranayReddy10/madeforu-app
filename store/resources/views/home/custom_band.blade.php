<section class="section">
    <div class="wrap">
        <div class="custom-band">
            <div class="custom-text">
                @if($s['eyebrow'])<span class="eyebrow light">{{ $s['eyebrow'] }}</span>@endif
                <h2>{{ $s['title'] }}</h2>
                <p>{{ $s['text'] }}</p>
                <div class="cta-actions">
                    @if(config('store.whatsapp'))<a class="btn wa" href="https://wa.me/{{ config('store.whatsapp') }}?text={{ rawurlencode('Hi! I have a custom gift idea: ') }}" target="_blank" rel="noopener">@include('partials.icon', ['i' => 'whatsapp']) Chat on WhatsApp</a>@endif
                    <a class="btn light" href="{{ url('contact-us') }}/">Send a message</a>
                </div>
            </div>
            <div class="custom-art" aria-hidden="true">
                @foreach($featured->concat($bestsellers)->unique('id')->filter(fn ($p) => $p->images->isNotEmpty())->take(3) as $p)
                    <span class="polaroid p{{ $loop->iteration }}">@include('partials.picture', ['path' => $p->images->first()->path, 'alt' => '', 'sizes' => '160px', 'lazy' => true])</span>
                @endforeach
            </div>
        </div>
    </div>
</section>
