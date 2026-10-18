<div class="row-inline attr-row" data-row>
    <input name="attrs[{{ $i }}][name]" value="{{ $a['name'] ?? '' }}" placeholder="Attribute, e.g. Size" data-attr-name>
    <input name="attrs[{{ $i }}][values]" value="{{ $a['values'] ?? '' }}" placeholder="Values, comma separated: A4, A3" class="grow" data-attr-values>
    <button type="button" class="link danger" data-remove-row>Remove</button>
</div>
