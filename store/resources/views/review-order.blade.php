@extends('layouts.app')
@section('content')
<div class="wrap narrow">
    <header class="listing-head"><h1>How was your gift?</h1></header>
    <p class="muted">Order {{ $order->number }} · Your review helps other people choose — photos of your gift are extra special 💝</p>
    @if($products->isEmpty())
        <div class="panel center"><p>You've already reviewed everything in this order. Thank you!</p><a href="{{ url('/') }}" class="btn primary">Back to shop</a></div>
    @else
    <form method="post" action="{{ request()->fullUrl() }}" enctype="multipart/form-data" class="review-order">
        @csrf
        @error('reviews')<p class="err">{{ $message }}</p>@enderror
        @foreach($products as $p)
            <section class="panel">
                <div class="mini-line"><img src="{{ $p->thumbUrl() }}" alt="" width="56" height="56"><span><b>{{ $p->name }}</b></span></div>
                <div class="rate-input" role="radiogroup" aria-label="Rating for {{ $p->name }}">
                    @for($i = 5; $i >= 1; $i--)<input type="radio" id="r{{ $p->id }}-{{ $i }}" name="reviews[{{ $p->id }}][rating]" value="{{ $i }}"><label for="r{{ $p->id }}-{{ $i }}" title="{{ $i }} stars">★</label>@endfor
                </div>
                <textarea name="reviews[{{ $p->id }}][body]" rows="3" maxlength="2000" placeholder="What did you (or they) love about it?">{{ old('reviews.'.$p->id.'.body') }}</textarea>
                <label class="small">Photos of your gift (up to 3)<input type="file" name="photos[{{ $p->id }}][]" accept="image/*" multiple></label>
            </section>
        @endforeach
        <div class="panel form-grid">
            <label class="span2">Your name (shown with the review)<input name="name" value="{{ old('name', \Illuminate\Support\Str::before($order->name, ' ')) }}" required maxlength="80"></label>
        </div>
        <button class="btn primary block">Submit review</button>
    </form>
    @endif
</div>
@endsection
