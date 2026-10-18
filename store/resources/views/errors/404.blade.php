@extends('layouts.app')
@section('content')
<div class="wrap narrow center empty">
    <h1>We couldn't find that page</h1>
    <p>It may have moved. Try searching, or have a look around.</p>
    <form action="{{ route('search') }}" class="search-page-box"><input type="search" name="q" placeholder="Search gifts" aria-label="Search"></form>
    <a href="{{ url('/') }}" class="btn primary">Go to home</a>
</div>
@endsection
