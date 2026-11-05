@extends('admin.layout')
@section('title', $redirect->exists ? 'Edit redirect' : 'New redirect')
@section('content')
<form method="post" class="card narrow" action="{{ $redirect->exists ? route('admin.redirects.update', $redirect) : route('admin.redirects.store') }}">
    @csrf
    @if ($redirect->exists) @method('PUT') @endif
    <label>From (old path or full URL) <input name="from_path" value="{{ old('from_path', $redirect->from_path) }}" required placeholder="/old-product-name/"></label>
    <label>To (new path or full URL) <input name="to_path" value="{{ old('to_path', $redirect->to_path) }}" required placeholder="/product/new-product-name/"></label>
    <label>Type
        <select name="status">
            <option value="301" @selected(old('status', $redirect->status) == 301)>301 — permanent (passes Google ranking)</option>
            <option value="302" @selected(old('status', $redirect->status) == 302)>302 — temporary</option>
        </select>
    </label>
    <button class="btn">Save redirect</button>
</form>
@endsection
