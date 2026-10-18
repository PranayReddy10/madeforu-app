{{-- Personalisation fields for one item. $prefix: input name (custom, or items[N][custom]); $suffix: makes ids unique. --}}
@php($photoField = $product->photoFieldId())
@foreach($product->customizationFields as $f)
    <div class="field" data-field="{{ $f->id }}">
        @if($f->type === 'images')
            @php($slots = $product->photoSlots($f))
            <div class="upload" data-upload data-min="{{ $f->min_files }}" data-max="{{ $f->max_files }}" data-base-min="{{ $f->min_files }}" data-base-max="{{ $f->max_files }}" data-name="{{ $prefix }}[{{ $f->id }}][]" data-required="{{ $f->required ? 1 : 0 }}" @if($f->id === $photoField) data-photo-target @endif>
                <div class="upload-head">
                    <label>{{ $f->label }} @if($f->required)<em>*</em>@endif</label>
                    <span class="upload-count"><b data-count>0</b> / <span data-max-label>{{ $f->max_files }}</span></span>
                </div>
                @if($f->help)<p class="help">{{ $f->help }}</p>@endif
                <div class="upload-slots">
                    @for($i = 0; $i < $slots; $i++)
                        <label class="slot" data-slot @if($i >= $f->max_files) hidden @endif>
                            <input type="file" accept="image/*,.heic,.heif" @if($slots > 1) multiple @endif hidden>
                            <span class="slot-empty"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg><small>{{ $f->options[$i]['label'] ?? ($slots > 1 ? 'Photo '.($i + 1) : 'Add photo') }}</small></span>
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
                    <option value="{{ $o['label'] }}" data-price="{{ (float) ($o['price'] ?? 0) }}" @if(! empty($o['photos'])) data-photos="{{ $o['photos'] }}" @endif>{{ $o['label'] }}@if(!empty($o['price']) && $o['price'] > 0) (+{{ money($o['price']) }})@endif @if(! empty($o['photos'])) · {{ $o['photos'] }} {{ \Illuminate\Support\Str::plural('photo', $o['photos']) }}@endif</option>
                @endforeach
            </select>
        @elseif($f->type === 'choice')
            <span class="lbl-like">{{ $f->label }} @if($f->required)<em>*</em>@endif <b class="choice-picked" data-choice-picked></b></span>
            <div class="choice-tiles" role="radiogroup" aria-label="{{ $f->label }}">
                @foreach($f->options ?? [] as $k => $o)
                    <label class="choice-tile">
                        <input type="radio" name="{{ $prefix }}[{{ $f->id }}]" value="{{ $o['label'] }}" data-price="{{ (float) ($o['price'] ?? 0) }}" @if(! empty($o['photos'])) data-photos="{{ $o['photos'] }}" @endif data-extra-choice @required($f->required) @checked(! $suffix && old('custom.'.$f->id) === $o['label'])>
                        <span class="ct-box">
                            @if(! empty($o['image']))<img src="{{ media_url($o['image']) }}" alt="{{ $o['label'] }}" loading="lazy" width="120" height="120">@else<span class="ct-letter">{{ mb_substr($o['label'], 0, 1) }}</span>@endif
                        </span>
                        <span class="ct-label">{{ $o['label'] }}@if(! empty($o['price']) && $o['price'] > 0)<small>+{{ money($o['price']) }}</small>@endif</span>
                    </label>
                @endforeach
            </div>
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
