@extends('emails.layout')
@section('preheader'){{ $title }}@endsection
@section('body')
    <h1 style="font-size:20px;margin:0 0 8px">{{ $title }}</h1>
    @foreach($lines as $line)<p style="margin:0 0 8px">{!! $line !!}</p>@endforeach
    @if($url)@include('emails.partials.button', ['url' => $url, 'label' => $button ?? 'Open in admin'])@endif
@endsection
