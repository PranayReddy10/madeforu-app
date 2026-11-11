{{-- Page header: eyebrow, title, intro. $title may contain <em> from the template (never from the database). --}}
<header class="page-hero {{ $class ?? '' }}">
    <div class="wrap">
        @include('partials.breadcrumbs', ['crumbs' => $crumbs])
        @if (! empty($eyebrow))<span class="eyebrow">{{ $eyebrow }}</span>@endif
        <h1>{!! $title !!}</h1>
        @if (! empty($intro))<p class="lead">{{ $intro }}</p>@endif
    </div>
</header>
