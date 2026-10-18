{{-- One image chosen from the media library. Posts the image path (or CDN URL) as $name. --}}
<div class="image-field">
    <span class="lbl">{{ $label }} @if (! empty($required))<em>*</em>@endif @if (! empty($hint))<small class="muted">{{ $hint }}</small>@endif</span>
    <div class="image-field-row">
        <img class="preview" data-preview-for="{{ $name }}" src="{{ $value ? media_url($value) : '' }}" alt="" @if (! $value) hidden @endif>
        <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        <div>
            <button type="button" class="btn ghost" data-media-pick data-multiple="0" data-input="{{ $name }}" data-folder="{{ $folder ?? 'general' }}">Choose image</button>
            <button type="button" class="link danger" data-media-clear data-input="{{ $name }}">Remove</button>
        </div>
    </div>
</div>
