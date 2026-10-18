@if($s['data']['banners']->isNotEmpty())<h1 class="sr">{{ config('store.name') }} - personalised photo gifts online in India</h1>@endif
<div class="wrap hero-wrap">@include('partials.hero-slider', ['banners' => $s['data']['banners']])</div>
