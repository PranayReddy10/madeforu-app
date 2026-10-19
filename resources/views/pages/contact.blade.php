@php
    $wa = config('store.whatsapp');
    $phone = config('store.phone');
    $email = config('store.email');
@endphp
@include('pages.hero', ['eyebrow' => 'We’re listening', 'title' => 'Let’s talk <em>gifts</em>', 'intro' => $page->intro, 'class' => 'hero-contact'])

<div class="wrap">
    <div class="contact-cards">
        @if ($wa)
        <a class="contact-card" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Hi '.config('store.name').'! ') }}" target="_blank" rel="noopener">
            <span class="ico-badge green">@include('partials.icon', ['i' => 'whatsapp'])</span>
            <b>WhatsApp us</b><span>Fastest reply · {{ $phone ?: '+'.$wa }}</span>
        </a>
        @endif
        @if ($phone)
        <a class="contact-card" href="tel:{{ preg_replace('/[^\d+]/', '', $phone) }}">
            <span class="ico-badge">@include('partials.icon', ['i' => 'phone'])</span>
            <b>Call us</b><span>{{ $phone }} · {{ config('store.hours') }}</span>
        </a>
        @endif
        <a class="contact-card" href="mailto:{{ $email }}">
            <span class="ico-badge ink">@include('partials.icon', ['i' => 'mail'])</span>
            <b>Email us</b><span>{{ $email }} · reply within a day</span>
        </a>
    </div>

    <div class="contact-grid">
        <section class="page-card contact-form" id="message">
            @if (session('contact_sent'))
                <div class="sent">
                    <span class="ico-badge green big">@include('partials.icon', ['i' => 'check'])</span>
                    <h2>Thanks — message received!</h2>
                    <p>We’ll get back to you by email or WhatsApp, usually within a few hours ({{ config('store.hours') }}).</p>
                    <a class="btn primary" href="{{ url('shop') }}/">Keep browsing gifts</a>
                </div>
            @else
                <h2>Send a message</h2>
                <p class="muted">Fill this in and we’ll reply by email or WhatsApp.</p>
                <form method="post" action="{{ route('contact.send') }}" class="cform">
                    @csrf
                    <div class="hp" aria-hidden="true"><label>Website <input name="website" tabindex="-1" autocomplete="off"></label></div>
                    <label>Your name <input name="name" value="{{ old('name', auth()->user()?->name) }}" required maxlength="120" autocomplete="name"></label>
                    <div class="row2">
                        <label>Mobile number <input type="tel" name="phone" value="{{ old('phone', auth()->user()?->phone) }}" required inputmode="tel" autocomplete="tel" placeholder="10-digit mobile" pattern="[\d\s+\-]{10,16}" title="10-digit mobile number"></label>
                        <label>Email <small class="muted">(optional)</small><input type="email" name="email" value="{{ old('email', auth()->user()?->email) }}" maxlength="191" autocomplete="email"></label>
                    </div>
                    <div class="row2">
                        <label>What’s it about?
                            <select name="topic">@foreach (\App\Http\Controllers\ContactController::TOPICS as $t)<option @selected(old('topic') === $t)>{{ $t }}</option>@endforeach</select>
                        </label>
                        <label>Order number <small class="muted">(if any)</small><input name="order_number" value="{{ old('order_number') }}" maxlength="40" placeholder="e.g. MFU1024"></label>
                    </div>
                    <label>Message <textarea name="message" rows="5" required minlength="5" maxlength="3000" placeholder="Tell us what you need — for a custom idea, describe it and we’ll suggest options.">{{ old('message') }}</textarea></label>
                    @if ($errors->any())<p class="form-error">{{ $errors->first() }}</p>@endif
                    <button class="btn primary block">Send message @include('partials.icon', ['i' => 'arrow'])</button>
                    <p class="muted small center">By sending this you agree to our <a href="{{ url('legal/privacy-policy-2') }}/">privacy policy</a>.</p>
                </form>
            @endif
        </section>

        <aside class="contact-side">
            <div class="page-card side-card">
                <h3>@include('partials.icon', ['i' => 'pin']) Visit / write</h3>
                <p>{{ config('store.name') }}<br>{!! nl2br(e(str_replace(', ', ",\n", config('store.address')))) !!}</p>
                @if (config('store.map_url'))<a class="link-btn" href="{{ config('store.map_url') }}" target="_blank" rel="noopener">Get directions →</a>@endif
            </div>
            <div class="page-card side-card">
                <h3>@include('partials.icon', ['i' => 'clock']) Hours</h3>
                <p>{{ config('store.hours') }}<br><span class="muted">Sunday closed · orders online 24×7</span></p>
            </div>
            @if (trim(strip_tags((string) $page->body)) !== '')
                <div class="page-card side-card prose">{!! $page->body !!}</div>
            @endif
            <div class="page-card side-card quick-links">
                <h3>@include('partials.icon', ['i' => 'sparkle']) Quick help</h3>
                <a href="{{ route('track') }}">Track my order →</a>
                <a href="{{ url('legal/refund-cancellation-policy') }}/">Damaged or wrong item →</a>
                <a href="{{ url('legal/shipping-delivery-policy') }}/">Delivery times →</a>
                @if (config('store.social.instagram'))<a href="{{ config('store.social.instagram') }}" target="_blank" rel="noopener">See our work on Instagram →</a>@endif
            </div>
        </aside>
    </div>
</div>
