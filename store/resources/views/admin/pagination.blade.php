@if ($paginator->hasPages())
<nav class="pager">
    @if ($paginator->onFirstPage())<span class="off">‹ Prev</span>@else<a href="{{ $paginator->previousPageUrl() }}">‹ Prev</a>@endif
    <span>Page {{ $paginator->currentPage() }} of {{ $paginator->lastPage() }}</span>
    @if ($paginator->hasMorePages())<a href="{{ $paginator->nextPageUrl() }}">Next ›</a>@else<span class="off">Next ›</span>@endif
</nav>
@endif
