@include('pages.hero', ['title' => e($page->title), 'intro' => $page->intro])
<div class="wrap narrow">
    <article class="prose page-card">{!! $page->body !!}</article>
</div>
