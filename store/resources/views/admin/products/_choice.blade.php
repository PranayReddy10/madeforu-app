{{-- One picture option of a "Choose a design" field. --}}
@php $name = $base.'['.$k.']'; @endphp
<div class="choice-row" data-choice-row>
    <img class="preview" data-preview-for="{{ $name }}[image]" src="{{ ! empty($c['image']) ? media_url($c['image']) : '' }}" alt="" @if (empty($c['image'])) hidden @endif>
    <input type="hidden" name="{{ $name }}[image]" value="{{ $c['image'] ?? '' }}">
    <button type="button" class="btn small ghost" data-media-pick data-multiple="0" data-input="{{ $name }}[image]" data-folder="options">Picture</button>
    <input name="{{ $name }}[label]" value="{{ $c['label'] ?? '' }}" placeholder="e.g. Heart" maxlength="60">
    <input type="number" step="0.01" min="0" name="{{ $name }}[price]" value="{{ $c['price'] ?? 0 }}" title="Extra price (₹)" class="price-in">
    <input type="number" min="1" max="50" name="{{ $name }}[photos]" value="{{ $c['photos'] ?? '' }}" title="Photos needed with this option (blank = as the photo field)" placeholder="photos" class="price-in">
    <button type="button" class="link danger" data-choice-remove aria-label="Remove option">✕</button>
</div>
