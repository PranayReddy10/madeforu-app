@extends('layouts.app')
@section('content')
<div class="wrap">
    <header class="listing-head"><h1>Wishlist</h1></header>
    @if($products->isEmpty())
        <div class="empty"><p>Nothing saved yet.</p><a href="{{ url('shop') }}/" class="btn primary">Browse gifts</a></div>
    @else
        <div class="grid">@foreach($products as $p)@include('partials.product-card', ['p' => $p])@endforeach</div>
    @endif
</div>
@endsection
