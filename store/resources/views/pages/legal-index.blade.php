@include('pages.hero', ['eyebrow' => 'Policies', 'title' => e($page->title === 'Legal' ? 'Our policies' : $page->title), 'intro' => $page->intro, 'class' => 'hero-legal'])
<div class="wrap narrow">
    <div class="policy-cards">
        @foreach ($policies as $p)
            <a href="{{ $p->url() }}" class="policy-card">
                <span class="ico-badge">@include('partials.icon', ['i' => \App\Support\DefaultPages::icon($p->slug)])</span>
                <span><b>{{ $p->title }}</b><small>{{ \Illuminate\Support\Str::limit($p->intro ?: strip_tags((string) $p->body), 110) }}</small></span>
                @include('partials.icon', ['i' => 'arrow', 'class' => 'go'])
            </a>
        @endforeach
    </div>
    @if (trim(strip_tags((string) $page->body)) !== '')<article class="prose page-card">{!! $page->body !!}</article>@endif
    @include('pages.help-strip')
</div>
