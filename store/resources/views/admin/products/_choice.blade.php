{{-- One picture option of a "Choose a design" field. --}}
@php $name = $base.'['.$k.']'; @endphp
<div class="choice-row" data-choice-row>
    <img class="preview" data-preview-for="{{ $name }}[image]" src="{{ ! empty($c['image']) ? media_url($c['image']) : '' }}" alt="" @if (empty($c['image'])) hidden @endif>
    <input type="hidden" name="{{ $name }}[image]" value="{{ $c['image'] ?? '' }}">
    <button type="button" class="btn small ghost ch-pic" data-media-pick data-multiple="0" data-input="{{ $name }}[image]" data-folder="options">Picture</button>
    <label class="ch-f ch-name"><small>Option name</small><input name="{{ $name }}[label]" value="{{ $c['label'] ?? '' }}" placeholder="e.g. Heart" maxlength="60"></label>
    <label class="ch-f"><small>Extra ₹</small><input type="number" step="0.01" min="0" name="{{ $name }}[price]" value="{{ $c['price'] ?? 0 }}"></label>
    <label class="ch-f"><small>Photos</small><input type="number" min="1" max="50" name="{{ $name }}[photos]" value="{{ $c['photos'] ?? '' }}" placeholder="auto" title="Photos the customer uploads with this option (blank = as the photo field)"></label>
    <button type="button" class="link danger ch-x" data-choice-remove aria-label="Remove option" title="Remove option">✕</button>
</div>
