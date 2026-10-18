@extends('admin.layout')
@section('title', $banner->exists ? 'Edit banner' : 'New banner')
@section('content')
<form method="post" enctype="multipart/form-data" class="card narrow" action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}">
    @csrf
    @if ($banner->exists) @method('PUT') @endif
    <label>Title <input name="title" value="{{ old('title', $banner->title) }}" required></label>
    <label>Subtitle <input name="subtitle" value="{{ old('subtitle', $banner->subtitle) }}"></label>
    <label>Image <small class="muted">wide, about 1600×700; a version for phones crops the centre</small><input type="file" name="image" accept="image/*" @required(! $banner->exists)></label>
    @if ($banner->image)<img class="preview" src="{{ media_url($banner->image) }}" alt="">@endif
    <div class="row3">
        <label>Link <input name="link" value="{{ old('link', $banner->link) }}" placeholder="/product-category/photo-frames/"></label>
        <label>Button text <input name="button" value="{{ old('button', $banner->button) }}" placeholder="Shop now"></label>
        <label>Order <input type="number" name="position" value="{{ old('position', $banner->position) }}"></label>
    </div>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active))> Active</label>
    <button class="btn">Save banner</button>
</form>
@if ($banner->exists)
<form method="post" action="{{ route('admin.banners.destroy', $banner) }}" class="card narrow danger-zone">@csrf @method('DELETE')<button class="btn danger" data-confirm="Delete this banner?">Delete banner</button></form>
@endif
@endsection
