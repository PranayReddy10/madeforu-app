<div class="g-row" data-gallery-row draggable="true">
    <input type="hidden" name="gallery[{{ $i }}][path]" value="{{ $g['path'] ?? '' }}">
    <div class="g-img"><img src="{{ ! empty($g['path']) ? media_url($g['path']) : '' }}" alt="" loading="lazy"><span class="g-main" hidden>Main image</span></div>
    <input name="gallery[{{ $i }}][alt]" value="{{ $g['alt'] ?? '' }}" placeholder="Alt text (describe the photo)" maxlength="255">
    <div class="g-actions">
        <button type="button" class="icon" data-move="-1" title="Move left" aria-label="Move earlier">←</button>
        <button type="button" class="icon" data-move="1" title="Move right" aria-label="Move later">→</button>
        <button type="button" class="link danger" data-gallery-remove>Remove</button>
    </div>
</div>
