@extends('layouts.app')
@section('content')
<div class="wrap narrow center empty">
    <h1>This page timed out</h1>
    <p>For your security the form expired. Please go back, refresh the page and try again.</p>
    <a href="{{ url()->previous() }}" class="btn primary">Go back</a>
</div>
@endsection
