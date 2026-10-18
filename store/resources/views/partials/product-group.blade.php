{{-- Products in the chosen home layout: rail (swipe), grid4, grid6 or feature (one big + 4). --}}
@php
    $layout = $layout ?? 'rail';
    $take = ['grid4' => 4, 'grid6' => 6, 'feature' => 5][$layout] ?? null;
    $items = $take ? $list->take($take) : $list;
@endphp
@if($layout === 'rail')
    <div class="rail">@foreach($items as $p)@include('partials.product-card', ['p' => $p, 'eager' => ($eager ?? false) && $loop->index < 2])@endforeach</div>
@else
    <div class="pgrid pgrid-{{ $layout }}">
        @foreach($items as $p)
            @include('partials.product-card', ['p' => $p, 'eager' => ($eager ?? false) && $loop->index < 2, 'big' => $layout === 'feature' && $loop->first])
        @endforeach
    </div>
@endif
