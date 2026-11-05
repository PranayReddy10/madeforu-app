@extends('layouts.app')
@section('body_class', 'page-home')
@section('content')
{{-- Sections, their order and texts come from Admin → Home page (App\Support\HomeLayout). --}}
@foreach($sections as $s)
    @include('home.'.$s['type'], ['s' => $s])
@endforeach
@endsection
