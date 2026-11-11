@if($s['data']['category'] && $s['data']['products']->isNotEmpty())
    @include('partials.home-spotlight', ['s' => (object) $s['data'], 'n' => (($s['data']['n'] - 1) % 3) + 1, 'layout' => $s['layout']])
@endif
