{{-- Festival countdown on every page while a campaign is on (Admin → Campaign). --}}
@php($C = \App\Support\Campaign::class)
@if($C::active())
    <a class="camp-strip" href="{{ $C::url() }}">
        <span class="camp-lamp" aria-hidden="true">🪔</span>
        @if($C::beforeDeadline())
            <span><b>{{ $C::name() }} gifts</b> — order by {{ $C::deadline()->format('D, j M') }} for delivery in time</span>
            <span class="camp-count" data-countdown="{{ $C::deadline()->toIso8601String() }}"></span>
        @else
            <span><b>Last-minute {{ $C::name() }} gift?</b> Message us on WhatsApp — we'll try our best</span>
        @endif
        @if($C::coupon() && $C::discount() > 0)<span class="camp-code">{{ rtrim(rtrim(number_format($C::discount(), 1), '0'), '.') }}% off · <b>{{ $C::coupon() }}</b></span>@endif
    </a>
@endif
