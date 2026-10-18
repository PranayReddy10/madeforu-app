<nav class="crumbs" aria-label="Breadcrumb">
    @foreach($crumbs as $i => [$name, $link])
        @if($loop->last)<span aria-current="page">{{ $name }}</span>@else<a href="{{ $link }}">{{ $name }}</a><span class="sep">/</span>@endif
    @endforeach
</nav>
