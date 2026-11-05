<aside class="help-strip">
    <div>
        <b>Need a hand?</b>
        <span>We usually reply within a few hours, {{ config('store.hours') }}.</span>
    </div>
    <div class="help-actions">
        @if (config('store.whatsapp'))<a class="btn wa small" href="https://wa.me/{{ config('store.whatsapp') }}" rel="noopener" target="_blank">@include('partials.icon', ['i' => 'whatsapp']) WhatsApp</a>@endif
        <a class="btn small" href="{{ url('contact-us') }}/">Contact us</a>
    </div>
</aside>
