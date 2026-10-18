<div class="usp-bar" aria-label="Why shop with us">
    <div class="wrap usp-track">
        <span>@include('partials.icon', ['i' => 'hand']) Hand-made to order</span>
        <span>@include('partials.icon', ['i' => 'truck']) Free shipping over {{ money(setting('free_shipping_over')) }}</span>
        @if(setting('cod_enabled'))<span>@include('partials.icon', ['i' => 'cash']) Cash on delivery</span>@endif
        <span>@include('partials.icon', ['i' => 'shield']) Secure UPI &amp; cards</span>
        @if($rating['count'] >= 3)<span>@include('partials.icon', ['i' => 'star']) {{ $rating['avg'] }}★ from {{ $rating['count'] }} reviews</span>@endif
    </div>
</div>
