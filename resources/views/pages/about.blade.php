@include('pages.hero', ['eyebrow' => 'Handmade in '.config('store.city'), 'title' => 'Gifts that are <em>actually</em> personal', 'intro' => $page->intro, 'class' => 'hero-about'])

<div class="wrap">
    <ul class="stat-strip">
        <li>@include('partials.icon', ['i' => 'hand'])<b>Made by hand</b><span>to order, never off a shelf</span></li>
        <li>@include('partials.icon', ['i' => 'camera'])<b>Your photos</b><span>names, dates &amp; messages</span></li>
        <li>@include('partials.icon', ['i' => 'truck'])<b>All-India delivery</b><span>tracked with Delhivery</span></li>
        <li>@include('partials.icon', ['i' => 'shield'])<b>Safe checkout</b><span>UPI, cards{{ setting('cod_enabled') ? ' & COD' : '' }}</span></li>
    </ul>

    <section class="story">
        <div class="story-text">
            <span class="eyebrow">Our story</span>
            <h2>Small team, big on <em>feelings</em></h2>
            <div class="prose">{!! $page->body !!}</div>
        </div>
        @if ($showcase->count() >= 2)
            <div class="story-collage">
                @foreach ($showcase as $p)
                    <a href="{{ $p->url() }}" class="tile t{{ $loop->iteration }}">@include('partials.picture', ['path' => $p->images->first()->path, 'alt' => $p->name, 'sizes' => '(max-width: 640px) 45vw, 260px', 'lazy' => true])</a>
                @endforeach
            </div>
        @endif
    </section>

    <section class="how-we-work">
        <span class="eyebrow">How we work</span>
        <h2>Every order is made from scratch</h2>
        <ol class="steps steps-cards">
            <li><span class="step-n">1</span><h3>You personalise it</h3><p>Share your photo, text or design choices right on the product page. Your gift, your details.</p></li>
            <li><span class="step-n">2</span><h3>We make it by hand</h3><p>Each piece is produced and quality-checked to order in our {{ config('store.city') }} studio.</p></li>
            <li><span class="step-n">3</span><h3>Shipped with tracking</h3><p>We dispatch across India via Delhivery, so you always know where your gift is.</p></li>
        </ol>
    </section>

    @if ($menuCategories->isNotEmpty())
    <section class="section">
        <div class="section-head"><div><h2>What we make</h2><p>Pick a gift and make it yours</p></div><a href="{{ route('categories') }}">All categories</a></div>
        <div class="chip-cloud">
            @foreach ($menuCategories as $c)<a href="{{ $c->url() }}">{{ $c->name }}</a>@endforeach
        </div>
    </section>
    @endif

    @if ($reviews->isNotEmpty())
    <section class="section">
        <div class="section-head"><h2>Kind words</h2></div>
        <div class="rail reviews-rail">
            @foreach ($reviews as $r)
                <figure class="quote">
                    <div class="stars-sm"><span style="--r: {{ $r->rating }}"></span></div>
                    <blockquote>{{ \Illuminate\Support\Str::limit($r->body, 180) }}</blockquote>
                    <figcaption><b>{{ $r->name }}</b></figcaption>
                </figure>
            @endforeach
        </div>
    </section>
    @endif

    <section class="cta-band">
        <div>
            <h2>Let’s make <em>something</em> together</h2>
            <p>Got a custom idea or a bulk order? We’re happy to help — usually within 24 hours.</p>
        </div>
        <div class="cta-actions">
            <a class="btn light" href="{{ url('shop') }}/">Shop personalised gifts</a>
            @if (config('store.whatsapp'))<a class="btn wa" href="https://wa.me/{{ config('store.whatsapp') }}" target="_blank" rel="noopener">@include('partials.icon', ['i' => 'whatsapp']) WhatsApp us</a>@endif
        </div>
    </section>

    <div class="contact-cards three">
        <div class="contact-card static"><span class="ico-badge">@include('partials.icon', ['i' => 'pin'])</span><b>Visit / write</b><span>{{ config('store.address') }}</span></div>
        <a class="contact-card" href="{{ url('contact-us') }}/"><span class="ico-badge ink">@include('partials.icon', ['i' => 'phone'])</span><b>Talk to us</b><span>{{ config('store.phone') }}<br>{{ config('store.email') }}</span></a>
        @if (config('store.social.instagram'))
            <a class="contact-card" href="{{ config('store.social.instagram') }}" target="_blank" rel="noopener"><span class="ico-badge">@include('partials.icon', ['i' => 'instagram'])</span><b>Follow along</b><span>{{ '@'.basename(rtrim(config('store.social.instagram'), '/')) }} · new designs &amp; behind the scenes</span></a>
        @endif
    </div>
</div>
