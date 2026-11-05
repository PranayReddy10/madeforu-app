@include('pages.hero', ['eyebrow' => 'Policies', 'title' => e($page->title), 'intro' => $page->intro, 'class' => 'hero-legal'])
<div class="wrap legal-layout">
    <nav class="legal-nav" aria-label="Policies">
        @foreach ($policies as $p)
            <a href="{{ $p->url() }}" class="{{ $p->is($page) ? 'on' : '' }}">@include('partials.icon', ['i' => \App\Support\DefaultPages::icon($p->slug)]) {{ $p->title }}</a>
        @endforeach
    </nav>
    <div>
        <article class="prose page-card legal-body">
            <p class="updated">@include('partials.icon', ['i' => 'clock']) Last updated {{ $page->updated_at?->format('j F Y') }}</p>
            {!! $page->body !!}
        </article>
        @include('pages.help-strip')
    </div>
</div>
