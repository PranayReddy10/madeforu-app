@extends('layouts.app')
@section('body_class', 'page-cms page-'.($template ?: 'default'))
@section('content')
@include('pages.'.(in_array($template, ['about', 'contact', 'legal', 'legal-index'], true) ? $template : 'default'))
@endsection
