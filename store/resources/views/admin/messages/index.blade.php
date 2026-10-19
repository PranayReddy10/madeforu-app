@extends('admin.layout')
@section('title', 'Messages')
@section('content')
<nav class="tabs" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:10px">
    <a class="btn small {{ $spamView ? 'ghost' : '' }}" href="{{ route('admin.messages.index') }}">Inbox</a>
    <a class="btn small {{ $spamView ? '' : 'ghost' }}" href="{{ route('admin.messages.index', ['box' => 'spam']) }}">Spam ({{ $spamCount }})</a>
    @if ($spamView && $spamCount)<form method="post" action="{{ route('admin.messages.empty-spam') }}" style="margin-left:auto">@csrf @method('DELETE')<button class="btn small danger" data-confirm="Delete all {{ $spamCount }} spam messages?">Empty spam</button></form>@endif
</nav>
@if ($spamView)<p class="muted small">Filtered automatically (sales pitches, several links, blocked senders) — no email was sent for these. Bots that fill hidden fields or send within seconds are thrown away and not listed. Block more emails, numbers or words in <a href="{{ route('admin.settings') }}#contact-form">Settings → Contact form</a>.</p>@endif
<p class="muted small">Sent from the <a href="{{ url('contact-us') }}/" target="_blank">Contact page</a>. Each one is also emailed to the shop — reply to that email to answer.</p>
@forelse ($messages as $m)
    @php $wa = \App\Services\WhatsApp::number((string) $m->phone); @endphp
    <section class="card">
        <div style="display:flex;justify-content:space-between;gap:12px;flex-wrap:wrap">
            <div><strong>{{ $m->name }}</strong> @if (! $m->read_at)<span class="badge err">New</span>@endif
                @if ($m->topic)<span class="badge">{{ $m->topic }}</span>@endif
                @if ($m->spam_reason)<span class="badge err">Spam · {{ $m->spam_reason }}</span>@endif
                @if ($m->phone)<br><b>📞 {{ $m->phone }}</b>@endif
                <br><small class="muted">{{ $m->created_at->format('d M Y, H:i') }} · {{ $m->created_at->diffForHumans() }}</small></div>
            <div class="nowrap">
                @if ($m->phone)<a class="btn small ghost" href="tel:{{ $m->phone }}">Call</a>@endif
                @if ($m->email)<a class="btn small ghost" href="mailto:{{ $m->email }}?subject={{ rawurlencode('Re: your message to '.config('store.name')) }}">Email</a>@endif
                @if ($wa)<a class="btn small ghost" target="_blank" rel="noopener" href="https://wa.me/{{ $wa }}?text={{ rawurlencode('Hi '.\Illuminate\Support\Str::before($m->name, ' ').', this is '.config('store.name').' replying to your message.') }}">WhatsApp</a>@endif
                @if ($m->spam_reason)
                    <form method="post" action="{{ route('admin.messages.not-spam', $m) }}" style="display:inline">@csrf<button class="btn small ghost">Not spam</button></form>
                @else
                    <form method="post" action="{{ route('admin.messages.spam', $m) }}" style="display:inline">@csrf<button class="btn small ghost" data-confirm="Move to Spam and block this email and mobile number?">Spam</button></form>
                @endif
                <form method="post" action="{{ route('admin.messages.destroy', $m) }}" style="display:inline">@csrf @method('DELETE')<button class="btn small danger" data-confirm="Delete this message?">Delete</button></form>
            </div>
        </div>
        <p style="white-space:pre-wrap;margin:10px 0 6px">{{ $m->message }}</p>
        <small class="muted">{{ collect([$m->email, $m->phone, $m->order_number ? 'Order '.$m->order_number : null])->filter()->implode(' · ') }}</small>
    </section>
@empty
    <section class="card"><p class="muted">{{ $spamView ? 'No spam. 🎉' : 'No messages yet.' }}</p></section>
@endforelse
{{ $messages->links('admin.pagination') }}
@endsection
