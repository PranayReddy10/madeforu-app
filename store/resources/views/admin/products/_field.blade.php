<div class="rowbox" data-row>
    <input type="hidden" name="fields[{{ $i }}][id]" value="{{ $f['id'] ?? '' }}">
    <div class="row4">
        <label>Type
            <select name="fields[{{ $i }}][type]" data-field-type>
                @foreach ($fieldTypes as $k => $l)<option value="{{ $k }}" @selected(($f['type'] ?? '') === $k)>{{ $l }}</option>@endforeach
            </select>
        </label>
        <label class="span2">Label <input name="fields[{{ $i }}][label]" value="{{ $f['label'] ?? '' }}" placeholder="e.g. Upload your 4 photos"></label>
        <label>Order <input type="number" name="fields[{{ $i }}][position]" value="{{ $f['position'] ?? $i }}"></label>
    </div>
    <label>Help text <input name="fields[{{ $i }}][help]" value="{{ $f['help'] ?? '' }}"></label>
    <div class="row4">
        <label data-show="images">Min photos <input type="number" min="1" max="50" name="fields[{{ $i }}][min_files]" value="{{ $f['min_files'] ?? 1 }}"></label>
        <label data-show="images">Max photos <input type="number" min="1" max="50" name="fields[{{ $i }}][max_files]" value="{{ $f['max_files'] ?? 1 }}"></label>
        <label data-show="text textarea">Max characters <input type="number" min="1" name="fields[{{ $i }}][max_length]" value="{{ $f['max_length'] ?? '' }}"></label>
        <label>Extra price (₹) <input type="number" step="0.01" min="0" name="fields[{{ $i }}][extra_price]" value="{{ $f['extra_price'] ?? 0 }}"></label>
    </div>
    <label data-show="images">Photo labels <small class="muted">optional — one per line, shown on each upload box, e.g. “His photo” / “Her photo”</small>
        <textarea name="fields[{{ $i }}][slots]" rows="2" placeholder="His photo&#10;Her photo">{{ $f['slots'] ?? '' }}</textarea>
    </label>
    <label data-show="select">Choices — one per line, <code>Label|extra price</code>, or <code>Label|extra price|photos</code> when the option changes how many photos the customer uploads
        <textarea name="fields[{{ $i }}][options]" rows="3" placeholder="One side|0|1&#10;Two sides|49|2">{{ $f['options'] ?? '' }}</textarea>
    </label>
    <div data-show="choice" class="choice-editor" data-choices data-base="fields[{{ $i }}][choices]">
        <span class="lbl">Options <small class="muted">— a picture for each (shape, style, colour…), with an extra price if it costs more, and the number of photos it needs (blank = as the photo field)</small></span>
        <div class="choice-list" data-choice-list>
            @foreach (($f['choices'] ?? []) as $k => $c)
                @include('admin.products._choice', ['base' => "fields[{$i}][choices]", 'k' => $k, 'c' => $c])
            @endforeach
        </div>
        <button type="button" class="btn small ghost" data-choice-add>+ Add option</button>
    </div>
    <div class="row-inline">
        <label class="check"><input type="checkbox" name="fields[{{ $i }}][required]" value="1" @checked(! empty($f['required']))> Required</label>
        <button type="button" class="link danger" data-remove-row>Remove field</button>
    </div>
</div>
