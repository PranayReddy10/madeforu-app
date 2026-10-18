@extends('layouts.app')
@section('content')
<div class="wrap narrow center empty">
    <h1>Too many tries</h1>
    <p>Please wait a minute and try again.</p>
    <a href="{{ url('/') }}" class="btn primary">Go to home</a>
</div>
@endsection
