@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <div class="rv-thanks">
        <p class="rv-hearts">💝</p>
        <h1>Thank you{{ ($r['count'] ?? 1) > 1 ? ' for your reviews' : ' for your review' }}!</h1>
        <p class="muted">@if (! empty($r['published']))It’s live on our website now.@else It will appear on our website once we’ve had a look.@endif It really helps a small handmade shop like ours.</p>

        @if ($google)
            <div class="rv-google">
                <h2>Would you share it on Google too?</h2>
                <p class="small">It helps other people find us. Takes 20 seconds:</p>
                <ol class="small">
                    @if (! empty($r['text']))
                        <li>Copy your review
                            <div class="rv-text" id="rv-text">{{ $r['text'] }}</div>
                            <button type="button" class="btn small ghost" data-copy="{{ $r['text'] }}">Copy my review <small></small></button>
                        </li>
                        <li>Tap below, choose the stars and paste it.</li>
                    @else
                        <li>Tap below, choose the stars and add a line about your gift.</li>
                    @endif
                </ol>
                <a class="btn primary block" href="{{ $google }}" target="_blank" rel="noopener">Post on Google ↗</a>
            </div>
        @endif
        <a class="btn ghost block" href="{{ url('shop') }}/">Back to the shop</a>
    </div>
</div>
@endsection
