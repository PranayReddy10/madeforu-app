@extends('admin.layout')
@section('title', $page->exists ? 'Edit: '.$page->title : 'New page')
@section('content')
<form method="post" class="card" action="{{ $page->exists ? route('admin.pages.update', $page) : route('admin.pages.store') }}">
    @csrf
    @if ($page->exists) @method('PUT') @endif
    <div class="row2">
        <label>Title <input name="title" value="{{ old('title', $page->title) }}" required></label>
        <label>Slug <small class="muted">{{ url('/') }}/<b>slug</b>/</small><input name="slug" value="{{ old('slug', $page->slug) }}" placeholder="auto from title"></label>
    </div>
    <div class="row2">
        <label>Layout
            <select name="template">
                @foreach (['' => 'Standard page', 'about' => 'About us (story, how we work, contact)', 'contact' => 'Contact (cards, form, hours)', 'legal' => 'Policy (side menu of all policies)', 'legal-index' => 'Policy list'] as $v => $l)
                    <option value="{{ $v }}" @selected(old('template', $page->template) === $v || (! $v && ! old('template', $page->template)))>{{ $l }}</option>
                @endforeach
            </select>
        </label>
        <label>Intro <small class="muted">The line under the title, in the page header.</small><textarea name="intro" rows="2" maxlength="600">{{ old('intro', $page->intro) }}</textarea></label>
    </div>
    <label>Content (HTML) <textarea name="body" rows="18" class="mono">{{ old('body', $page->body) }}</textarea></label>
    <label>Meta title <small class="counter" data-for="p-mt" data-max="60"></small><input id="p-mt" name="meta_title" value="{{ old('meta_title', $page->meta_title) }}"></label>
    <label>Meta description <small class="counter" data-for="p-md" data-max="160"></small><textarea id="p-md" name="meta_description" rows="2" maxlength="320">{{ old('meta_description', $page->meta_description) }}</textarea></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $page->is_active))> Published</label>
    <button class="btn">Save page</button>
</form>
@if ($page->exists)
<form method="post" action="{{ route('admin.pages.destroy', $page) }}" class="card danger-zone">@csrf @method('DELETE')<button class="btn danger" data-confirm="Delete this page? Its URL will stop working.">Delete page</button></form>
@endif
@endsection
