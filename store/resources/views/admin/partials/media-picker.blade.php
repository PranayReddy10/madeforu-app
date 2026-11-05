{{-- Media library picker. Opened by any button with data-media-pick (see public/js/admin.js). --}}
<div class="mp" id="media-picker" hidden data-list-url="{{ route('admin.media.index') }}" data-upload-url="{{ route('admin.media.store') }}">
    <div class="mp-backdrop" data-mp-close></div>
    <div class="mp-panel" role="dialog" aria-modal="true" aria-labelledby="mp-title">
        <header class="mp-head">
            <h2 id="mp-title">Choose images</h2>
            <div class="mp-tabs" role="tablist">
                <button type="button" role="tab" class="on" data-mp-tab="library">Media library</button>
                <button type="button" role="tab" data-mp-tab="upload">Upload</button>
            </div>
            <button type="button" class="mp-x" data-mp-close aria-label="Close">×</button>
        </header>
        <div class="mp-body">
            <section data-mp-pane="library">
                <div class="mp-tools">
                    <input type="search" placeholder="Search images…" data-mp-search aria-label="Search images">
                    <select data-mp-folder aria-label="Folder">
                        <option value="">All folders</option>
                        @foreach (\App\Http\Controllers\Admin\MediaController::FOLDERS as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach
                    </select>
                </div>
                <div class="mp-grid" data-mp-grid></div>
                <p class="mp-empty muted" data-mp-empty hidden>No images found. Upload some in the Upload tab.</p>
                <div class="center"><button type="button" class="btn ghost" data-mp-more hidden>Load more</button></div>
            </section>
            <section data-mp-pane="upload" hidden>
                <label class="dropzone big" data-mp-drop>
                    <input type="file" accept="image/*" multiple hidden data-mp-file>
                    <strong>Drop images here or click to choose</strong>
                    <span class="muted">JPG, PNG, WebP or GIF, up to 10 MB each</span>
                </label>
                <ul class="mp-progress" data-mp-progress></ul>
            </section>
        </div>
        <footer class="mp-foot">
            <span class="muted" data-mp-count>No image selected</span>
            <span class="grow"></span>
            <button type="button" class="btn ghost" data-mp-close>Cancel</button>
            <button type="button" class="btn" data-mp-use disabled>Use selected</button>
        </footer>
    </div>
</div>
