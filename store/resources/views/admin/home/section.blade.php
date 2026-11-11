{{-- One home page section in the editor. $s: the section; names are sections[<id>][field]. --}}
@php
    [$typeName, $addable, $about] = \App\Support\HomeLayout::TYPES[$s['type']];
    $n = fn ($f) => 'sections['.$s['id'].']['.$f.']';
    $title = $s['title'] ?? null;
@endphp
<details class="hb-card {{ $s['on'] ? '' : 'off' }}" data-hb-card>
    <summary>
        <span class="hb-move">
            <button type="button" data-hb-up title="Move up" aria-label="Move up">▲</button>
            <button type="button" data-hb-down title="Move down" aria-label="Move down">▼</button>
        </span>
        <span class="hb-name"><b>{{ $typeName }}</b>@if ($title)<small>“{{ \Illuminate\Support\Str::limit($title, 50) }}”</small>@endif</span>
        <label class="hb-switch" title="Show on the home page" onclick="event.stopPropagation()">
            <input type="hidden" name="{{ $n('on') }}" value="0">
            <input type="checkbox" name="{{ $n('on') }}" value="1" @checked($s['on']) data-hb-on>
            <span></span>
        </label>
    </summary>
    <div class="hb-body">
        <input type="hidden" name="{{ $n('id') }}" value="{{ $s['id'] }}">
        <input type="hidden" name="{{ $n('type') }}" value="{{ $s['type'] }}">
        @if ($about)<p class="muted small">{{ $about }}</p>@endif

        @switch ($s['type'])
            @case('slider')
                <p><a class="btn small ghost" href="{{ route('admin.banners.index') }}">Edit slides in Banners →</a></p>
                @break
            @case('products')
                <div class="row2">
                    <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" maxlength="120" data-hb-title></label>
                    <label>Line under the heading <input name="{{ $n('subtitle') }}" value="{{ $s['subtitle'] }}" maxlength="160"></label>
                </div>
                <div class="row2">
                    <label>Which products
                        <select name="{{ $n('source') }}" data-hb-source>
                            @foreach (\App\Support\HomeLayout::SOURCES as $k => $l)<option value="{{ $k }}" @selected($s['source'] === $k)>{{ $l }}</option>@endforeach
                        </select>
                    </label>
                    <label>How many <small class="muted">(swipe row)</small><input type="number" name="{{ $n('limit') }}" value="{{ $s['limit'] }}" min="2" max="24"></label>
                </div>
                <label>Layout
                    <select name="{{ $n('layout') }}">@foreach (\App\Support\HomeLayout::LAYOUTS as $k => $l)<option value="{{ $k }}" @selected($s['layout'] === $k)>{{ $l }}</option>@endforeach</select>
                </label>

                <label data-hb-when="category" @if ($s['source'] !== 'category') hidden @endif>Category
                    <select name="{{ $n('category_id') }}"><option value="">Choose…</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected($s['category_id'] == $c->id)>{{ str_repeat('— ', substr_count($c->path, '/')) }}{{ $c->name }}</option>@endforeach</select>
                </label>
                <label data-hb-when="manual" @if ($s['source'] !== 'manual') hidden @endif>Products <small class="muted">Ctrl/Cmd-click to pick several; shown in this list's order</small>
                    <select name="{{ $n('product_ids') }}[]" multiple size="8">@foreach ($products as $p)<option value="{{ $p->id }}" @selected(in_array($p->id, $s['product_ids']))>{{ $p->name }}</option>@endforeach</select>
                </label>
                @break
            @case('category_row')
                <label>Category
                    <select name="{{ $n('category_id') }}"><option value="">Automatic — the next biggest category</option>@foreach ($categories as $c)<option value="{{ $c->id }}" @selected($s['category_id'] == $c->id)>{{ str_repeat('— ', substr_count($c->path, '/')) }}{{ $c->name }}</option>@endforeach</select>
                </label>
                <label>Layout
                    <select name="{{ $n('layout') }}">@foreach (\App\Support\HomeLayout::LAYOUTS as $k => $l)<option value="{{ $k }}" @selected($s['layout'] === $k)>{{ $l }}</option>@endforeach</select>
                </label>
                <p class="muted small">The picture is the category's image (Admin → Categories), or its first product.</p>
                @break
            @case('promo')
                @include('admin.partials.image-field', ['name' => $n('image'), 'label' => 'Picture', 'value' => $s['image'], 'folder' => 'banners', 'hint' => 'wide, about 1600×500'])
                @include('admin.partials.image-field', ['name' => $n('mobile_image'), 'label' => 'Phone picture', 'value' => $s['mobile_image'], 'folder' => 'banners', 'hint' => 'optional, about 1080×800'])
                <div class="row2">
                    <label>Title on the picture <input name="{{ $n('title') }}" value="{{ $s['title'] }}" maxlength="120" data-hb-title placeholder="Leave blank if the text is in the picture"></label>
                    <label>Small text <input name="{{ $n('text') }}" value="{{ $s['text'] }}" maxlength="200"></label>
                </div>
                <div class="row2">
                    <label>Button text <input name="{{ $n('button') }}" value="{{ $s['button'] }}" maxlength="40"></label>
                    <label>Link <input name="{{ $n('link') }}" value="{{ $s['link'] }}" placeholder="/product-category/magnets/"></label>
                </div>
                @break
            @case('text')
                <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" maxlength="160" data-hb-title></label>
                <label>Text <small class="muted">HTML allowed: &lt;p&gt;, &lt;b&gt;, &lt;a href&gt;, &lt;ul&gt;&lt;li&gt;</small><textarea name="{{ $n('body') }}" rows="6" class="mono">{{ $s['body'] }}</textarea></label>
                @break
            @case('steps')
                <div class="row2">
                    <label>Small line above <input name="{{ $n('eyebrow') }}" value="{{ $s['eyebrow'] }}"></label>
                    <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" data-hb-title></label>
                </div>
                @foreach ([1, 2, 3] as $i)
                    <div class="row2">
                        <label>Step {{ $i }} <input name="{{ $n('s'.$i.'_title') }}" value="{{ $s['s'.$i.'_title'] }}"></label>
                        <label>Step {{ $i }} text <input name="{{ $n('s'.$i.'_text') }}" value="{{ $s['s'.$i.'_text'] }}"></label>
                    </div>
                @endforeach
                <div class="row2">
                    <label>Button text <small class="muted">blank = no button</small><input name="{{ $n('button') }}" value="{{ $s['button'] }}"></label>
                    <label>Button link <input name="{{ $n('link') }}" value="{{ $s['link'] }}"></label>
                </div>
                @break
            @case('why')
                @foreach ([1, 2, 3, 4] as $i)
                    <div class="row2">
                        <label>Point {{ $i }} <input name="{{ $n('w'.$i.'_title') }}" value="{{ $s['w'.$i.'_title'] }}"></label>
                        <label>Point {{ $i }} text <input name="{{ $n('w'.$i.'_text') }}" value="{{ $s['w'.$i.'_text'] }}"></label>
                    </div>
                @endforeach
                @break
            @case('faq')
                <div class="row2">
                    <label>Small line above <input name="{{ $n('eyebrow') }}" value="{{ $s['eyebrow'] }}"></label>
                    <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" data-hb-title></label>
                </div>
                <div class="hb-faq" data-hb-faq data-name="{{ $n('items') }}">
                    @foreach ($s['items'] as $i => $f)
                        <div class="hb-qa">
                            <input name="{{ $n('items') }}[{{ $i }}][q]" value="{{ $f['q'] }}" placeholder="Question">
                            <textarea name="{{ $n('items') }}[{{ $i }}][a]" rows="2" placeholder="Answer">{{ $f['a'] }}</textarea>
                            <button type="button" class="link danger" data-hb-faq-remove>Remove</button>
                        </div>
                    @endforeach
                </div>
                <button type="button" class="btn small ghost" data-hb-faq-add>+ Add a question</button>
                @break
            @case('offers')
                <div class="row2">
                    <label>Small line above <input name="{{ $n('eyebrow') }}" value="{{ $s['eyebrow'] }}"></label>
                    <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" data-hb-title></label>
                </div>
                <label>Layout
                    <select name="{{ $n('layout') }}">@foreach (\App\Support\HomeLayout::LAYOUTS as $k => $l)<option value="{{ $k }}" @selected($s['layout'] === $k)>{{ $l }}</option>@endforeach</select>
                </label>
                @break
            @case('categories')
                <div class="row2">
                    <label>Heading <input name="{{ $n('title') }}" value="{{ $s['title'] }}" data-hb-title></label>
                    <label>Line under the heading <input name="{{ $n('subtitle') }}" value="{{ $s['subtitle'] }}"></label>
                </div>
                <label>Layout
                    <select name="{{ $n('layout') }}">
                        <option value="scroll" @selected($s['layout'] === 'scroll')>Round icons in one swipe row</option>
                        <option value="grid" @selected($s['layout'] === 'grid')>Square tiles, 4 per line on phones</option>
                    </select>
                </label>
                @break
            @default
                @foreach (['eyebrow' => 'Small line above', 'title' => 'Heading', 'subtitle' => 'Line under the heading', 'text' => 'Text', 'amounts' => 'Amounts (₹, comma separated)'] as $f => $label)
                    @if (array_key_exists($f, $s))
                        <label>{{ $label }} <input name="{{ $n($f) }}" value="{{ $s[$f] }}" @if ($f === 'title') data-hb-title @endif></label>
                    @endif
                @endforeach
        @endswitch

        @if ($addable)<button type="button" class="link danger hb-del" data-hb-delete>Delete this section</button>@endif
    </div>
</details>
