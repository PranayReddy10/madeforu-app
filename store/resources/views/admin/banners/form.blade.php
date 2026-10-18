@extends('admin.layout')
@section('title', $banner->exists ? 'Edit banner' : 'New banner')
@section('content')
<form method="post" enctype="multipart/form-data" class="card narrow" action="{{ $banner->exists ? route('admin.banners.update', $banner) : route('admin.banners.store') }}">
    @csrf
    @if ($banner->exists) @method('PUT') @endif
    <label>Title <input name="title" value="{{ old('title', $banner->title) }}" required></label>
    <label>Subtitle <input name="subtitle" value="{{ old('subtitle', $banner->subtitle) }}"></label>
    @include('admin.partials.image-field', ['name' => 'image', 'label' => 'Desktop image', 'value' => old('image', $banner->image), 'folder' => 'banners', 'hint' => 'wide, about 1920×720', 'required' => true])
    @include('admin.partials.image-field', ['name' => 'mobile_image', 'label' => 'Phone image', 'value' => old('mobile_image', $banner->mobile_image), 'folder' => 'banners', 'hint' => 'optional, taller, about 1080×1200. Without one, phones crop the centre of the desktop image.'])
    <div class="row3">
        <label>Link <input name="link" value="{{ old('link', $banner->link) }}" placeholder="/product-category/photo-frames/"></label>
        <label>Button text <input name="button" value="{{ old('button', $banner->button) }}" placeholder="Shop now"></label>
        <label>Order <input type="number" name="position" value="{{ old('position', $banner->position) }}"></label>
    </div>
    <label class="check"><input type="checkbox" name="show_text" value="1" @checked(old('show_text', $banner->show_text))> Show title, subtitle and button over the image <small class="muted">(untick if the text is already part of the image)</small></label>
    <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $banner->is_active))> Active</label>
    <button class="btn">Save banner</button>
</form>
@if ($banner->exists)
<form method="post" action="{{ route('admin.banners.destroy', $banner) }}" class="card narrow danger-zone">@csrf @method('DELETE')<button class="btn danger" data-confirm="Delete this banner?">Delete banner</button></form>
@endif
@endsection
