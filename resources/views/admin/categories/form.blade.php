@extends('admin.layout')
@section('title', $category->exists ? 'Edit: '.$category->name : 'New category')
@section('content')
<form method="post" enctype="multipart/form-data" class="card narrow"
      action="{{ $category->exists ? route('admin.categories.update', $category) : route('admin.categories.store') }}">
    @csrf
    @if ($category->exists) @method('PUT') @endif
    <label>Name <input name="name" value="{{ old('name', $category->name) }}" required></label>
    <label>Slug <small class="muted">keep unchanged for categories imported from WordPress</small><input name="slug" value="{{ old('slug', $category->slug) }}" placeholder="auto from name"></label>
    <label>Parent
        <select name="parent_id">
            <option value="">— None (top level) —</option>
            @foreach ($parents as $p)
                @continue($category->exists && $p->id === $category->id)
                <option value="{{ $p->id }}" @selected(old('parent_id', $category->parent_id) == $p->id)>{{ str_repeat('— ', substr_count($p->path, '/')) }}{{ $p->name }}</option>
            @endforeach
        </select>
    </label>
    <label>Description <textarea name="description" rows="4">{{ old('description', $category->description) }}</textarea></label>
    @include('admin.partials.image-field', ['name' => 'image', 'label' => 'Image', 'value' => old('image', $category->image), 'folder' => 'categories', 'hint' => 'square works best; shown as the round category icon'])
    <div class="row2">
        <label>Icon (emoji or short text) <input name="icon" value="{{ old('icon', $category->icon) }}" maxlength="40"></label>
        <label>Order <input type="number" name="position" value="{{ old('position', $category->position ?? 0) }}"></label>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $category->is_active))> Active</label>
    <label class="check"><input type="checkbox" name="show_on_home" value="1" @checked(old('show_on_home', $category->show_on_home))> Show on home page</label>

    <h2>SEO</h2>
    <label>Meta title <small class="counter" data-for="c-mt" data-max="60"></small><input id="c-mt" name="meta_title" value="{{ old('meta_title', $category->meta_title) }}"></label>
    <label>Meta description <small class="counter" data-for="c-md" data-max="160"></small><textarea id="c-md" name="meta_description" rows="3" maxlength="320">{{ old('meta_description', $category->meta_description) }}</textarea></label>
    <label>SEO content <small class="muted">HTML shown below the product grid — a few helpful paragraphs help this page rank</small><textarea name="seo_content" rows="8">{{ old('seo_content', $category->seo_content) }}</textarea></label>
    <button class="btn">{{ $category->exists ? 'Save category' : 'Create category' }}</button>
</form>
@if ($category->exists)
<form method="post" action="{{ route('admin.categories.destroy', $category) }}" class="card narrow danger-zone">
    @csrf @method('DELETE')
    <button class="btn danger" data-confirm="Delete this category? Its sub-categories move up a level and its URL stops working.">Delete category</button>
</form>
@endif
@endsection
