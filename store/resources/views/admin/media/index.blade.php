@extends('admin.layout')
@section('title', 'Media library')
@section('actions')
    <label class="btn" for="media-files">+ Upload images</label>
@endsection
@section('content')
<form method="get" class="card filters">
    <input type="search" name="q" value="{{ request('q') }}" placeholder="Search by name or alt text">
    <select name="folder">
        <option value="">All folders</option>
        @foreach ($folders as $k => $l)<option value="{{ $k }}" @selected(request('folder') === $k)>{{ $l }}</option>@endforeach
    </select>
    <button class="btn ghost">Filter</button>
</form>

<form method="post" action="{{ route('admin.media.store') }}" enctype="multipart/form-data" class="card media-drop" data-drop-upload>
    @csrf
    <input type="hidden" name="folder" value="{{ request('folder') ?: 'general' }}">
    <label class="dropzone">
        <input type="file" id="media-files" name="files[]" accept="image/*" multiple hidden onchange="this.form.submit()">
        <strong>Drop images here or click to choose</strong>
        <span class="muted">JPG, PNG, WebP or GIF, up to 10 MB each. Stored on {{ config('store.media_disk') === 'spaces' ? 'DigitalOcean Spaces' : 'this server' }}.</span>
    </label>
</form>

@if ($media->isEmpty())
    <div class="card muted">No images yet.</div>
@else
    <div class="media-grid">
        @foreach ($media as $m)
            <div class="media-card">
                <a href="{{ $m->url() }}" target="_blank" rel="noopener" class="media-thumb"><img src="{{ $m->url() }}" alt="{{ $m->alt }}" loading="lazy"></a>
                <div class="media-meta">
                    <div class="media-name" title="{{ $m->name }}">{{ $m->name }}</div>
                    <small class="muted">{{ \App\Http\Controllers\Admin\MediaController::FOLDERS[$m->folder] ?? $m->folder }}@if ($m->width) · {{ $m->width }}×{{ $m->height }}@endif @if ($m->isRemote()) · CDN @endif</small>
                    <form method="post" action="{{ route('admin.media.update', $m) }}" class="media-alt">
                        @csrf @method('PUT')
                        <input name="alt" value="{{ $m->alt }}" placeholder="Alt text">
                        <button class="link">Save</button>
                    </form>
                    <form method="post" action="{{ route('admin.media.destroy', $m) }}">
                        @csrf @method('DELETE')
                        <button class="link danger" data-confirm="Delete this image from the library?">Delete</button>
                    </form>
                </div>
            </div>
        @endforeach
    </div>
    {{ $media->links('admin.pagination') }}
@endif
@endsection
