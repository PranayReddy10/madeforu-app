@extends('layouts.app')
@section('content')
<div class="wrap narrow center empty">
    <h1>{{ $exception->getMessage() ?: 'This page isn’t available' }}</h1>
    <p>The link may have expired or belong to another account. If you think this is a mistake, message us on WhatsApp.</p>
    <a href="{{ url('/') }}" class="btn primary">Go to home</a>
</div>
@endsection
