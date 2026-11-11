<div class="rowbox" data-row>
    <label>Question <input name="faqs[{{ $i }}][q]" value="{{ $f['q'] ?? '' }}" placeholder="How long does delivery take?"></label>
    <label>Answer <textarea name="faqs[{{ $i }}][a]" rows="2">{{ $f['a'] ?? '' }}</textarea></label>
    <button type="button" class="link danger" data-remove-row>Remove FAQ</button>
</div>
