{{-- Personalisation fields for one item. $prefix: input name (custom, or items[N][custom]); $suffix: makes ids unique. --}}
@foreach($product->customizationFields as $f)
    <div class="field" data-field="{{ $f->id }}">
        @if($f->type === 'images')
            <div class="upload" data-upload data-min="{{ $f->min_files }}" data-max="{{ $f->max_files }}" data-name="{{ $prefix }}[{{ $f->id }}][]" data-required="{{ $f->required ? 1 : 0 }}">
                <div class="upload-head">
                    <label>{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                    <span class="upload-count"><b data-count>0</b> / {{ $f->max_files }}</span>
                </div>
                @if($f->help)<p class="help">{{ $f->help }}</p>@endif
                <div class="upload-slots">
                    @for($i = 0; $i < $f->max_files; $i++)
                        <label class="slot" data-slot>
                            <input type="file" accept="image/*,.heic,.heif" @if($f->max_files > 1) multiple @endif hidden>
                            <span class="slot-empty"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><small>{{ $f->options[$i]['label'] ?? ($f->max_files > 1 ? 'Photo '.($i + 1) : 'Add photo') }}</small></span>
                        </label>
                    @endfor
                </div>
            </div>
        @elseif($f->type === 'text')
            <label for="cf{{ $f->id }}{{ $suffix }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
            <input id="cf{{ $f->id }}{{ $suffix }}" type="text" name="{{ $prefix }}[{{ $f->id }}]" value="{{ $suffix ? '' : old('custom.'.$f->id) }}" @if($f->max_length) maxlength="{{ $f->max_length }}" @endif @required($f->required) data-count-chars>
        @elseif($f->type === 'textarea')
            <label for="cf{{ $f->id }}{{ $suffix }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
            <textarea id="cf{{ $f->id }}{{ $suffix }}" name="{{ $prefix }}[{{ $f->id }}]" rows="3" @if($f->max_length) maxlength="{{ $f->max_length }}" @endif @required($f->required) data-count-chars>{{ $suffix ? '' : old('custom.'.$f->id) }}</textarea>
        @elseif($f->type === 'select')
            <label for="cf{{ $f->id }}{{ $suffix }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
            <select id="cf{{ $f->id }}{{ $suffix }}" name="{{ $prefix }}[{{ $f->id }}]" @required($f->required) data-extra-select>
                <option value="">Choose…</option>
                @foreach($f->options ?? [] as $o)
                    <option value="{{ $o['label'] }}" data-price="{{ (float) ($o['price'] ?? 0) }}">{{ $o['label'] }}@if(!empty($o['price']) && $o['price'] > 0) (+{{ money($o['price']) }})@endif</option>
                @endforeach
            </select>
        @elseif($f->type === 'color')
            <label for="cf{{ $f->id }}{{ $suffix }}">{{ $f->label }}</label>
            <input id="cf{{ $f->id }}{{ $suffix }}" type="color" name="{{ $prefix }}[{{ $f->id }}]" value="{{ old('custom.'.$f->id, '#f04577') }}">
        @elseif($f->type === 'date')
            <label for="cf{{ $f->id }}{{ $suffix }}">{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
            <input id="cf{{ $f->id }}{{ $suffix }}" type="date" name="{{ $prefix }}[{{ $f->id }}]" value="{{ $suffix ? '' : old('custom.'.$f->id) }}" @required($f->required)>
        @endif
        @if($f->extra_price > 0)<p class="help">+{{ money($f->extra_price) }}</p>@endif
        @unless($suffix) @error('custom.'.$f->id)<p class="err">{{ $message }}</p>@enderror @endunless
    </div>
@endforeach
