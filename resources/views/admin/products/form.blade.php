@extends('admin.layout')
@section('title', $product->exists ? 'Edit: '.$product->name : 'New product')
@section('actions')
    @if ($product->exists)
        <a class="btn ghost" href="{{ $product->url() }}" target="_blank" rel="noopener">View on store ↗</a>
    @endif
@endsection
@section('content')
@php
    $selectedCats = old('categories', $product->exists ? $product->categories->pluck('id')->all() : []);
    $attrs = old('attrs', $product->exists ? $product->productAttributes->map(fn ($a) => ['name' => $a->name, 'values' => implode(', ', $a->values ?? [])])->all() : []);
    $variants = old('variants', $product->exists ? $product->variants->map(fn ($v) => [
        'id' => $v->id,
        'options' => collect($v->options)->map(fn ($val, $k) => "$k=$val")->implode('; '),
        'price' => $v->price, 'sale_price' => $v->sale_price, 'sku' => $v->sku, 'in_stock' => $v->in_stock, 'stock_qty' => $v->stock_qty, 'image' => $v->image,
    ])->all() : []);
    $fields = old('fields', $product->exists ? $product->customizationFields->map(fn ($f) => [
        'id' => $f->id, 'type' => $f->type, 'label' => $f->label, 'help' => $f->help, 'required' => $f->required,
        'min_files' => $f->min_files, 'max_files' => $f->max_files, 'max_length' => $f->max_length,
        'options' => $f->type === 'select' ? collect($f->options ?? [])->map(fn ($o) => $o['label'].'|'.($o['price'] ?? 0).(! empty($o['photos']) ? '|'.$o['photos'] : ''))->implode("\n") : '',
        'choices' => $f->type === 'choice' ? array_values($f->options ?? []) : [],
        'slots' => $f->type === 'images' ? collect($f->options ?? [])->pluck('label')->implode("\n") : '',
        'extra_price' => $f->extra_price, 'position' => $f->position,
    ])->all() : [
        ['type' => 'images', 'label' => 'Upload your photo', 'help' => 'Clear, well-lit photos print best.', 'required' => true, 'min_files' => 1, 'max_files' => 1, 'position' => 0],
    ]);
    $faqs = old('faqs', $product->faqs ?? []);
    $gallery = old('gallery', $product->exists ? $product->images->map(fn ($img) => ['path' => $img->path, 'alt' => $img->alt])->all() : []);
    $fieldTypes = ['images' => 'Photo upload', 'text' => 'Text (one line)', 'textarea' => 'Text (multi-line)', 'select' => 'Choice (dropdown)', 'choice' => 'Choose a design (picture tiles)', 'color' => 'Colour', 'date' => 'Date'];
@endphp

<form method="post" enctype="multipart/form-data" id="product-form"
      action="{{ $product->exists ? route('admin.products.update', $product) : route('admin.products.store') }}">
    @csrf
    @if ($product->exists) @method('PUT') @endif

    <div class="grid-main">
    <div>
        <section class="card">
            <h2>Basics</h2>
            <label>Name <input name="name" id="f-name" value="{{ old('name', $product->name) }}" required maxlength="255"></label>
            <label>URL slug <small class="muted">{{ url('product') }}/<b id="slug-preview">{{ old('slug', $product->slug) }}</b>/ — keep it unchanged for products imported from WordPress</small>
                <input name="slug" id="f-slug" value="{{ old('slug', $product->slug) }}" maxlength="255" placeholder="auto from name">
            </label>
            <div class="row3">
                <label>SKU <input name="sku" value="{{ old('sku', $product->sku) }}"></label>
                <label>Type
                    <select name="type" id="f-type">
                        <option value="simple" @selected(old('type', $product->type) === 'simple')>Simple</option>
                        <option value="variable" @selected(old('type', $product->type) === 'variable')>Variable (options)</option>
                    </select>
                </label>
                <label>Production days <input type="number" name="production_days" min="0" max="60" value="{{ old('production_days', $product->production_days) }}"></label>
            </div>
            <div class="row3">
                <label>Regular price (₹) <input type="number" step="0.01" min="0" name="price" id="f-price" value="{{ old('price', $product->price) }}" required></label>
                <label>Sale price (₹) <input type="number" step="0.01" min="0" name="sale_price" value="{{ old('sale_price', $product->sale_price) }}"></label>
                <label>Weight (grams) <small class="muted">packed, e.g. 350</small> <input type="number" step="1" min="0" max="30000" name="weight_g" value="{{ old('weight_g', $product->weight_kg ? (int) round(\App\Services\Parcel::itemGrams($product)) : '') }}"></label>
            </div>
            <h3>Packed size <small class="muted">— used for live Delhivery delivery charges and sent with every shipment</small></h3>
            <div class="row4">
                <label>Length (cm) <input type="number" step="0.1" min="0" name="length_cm" value="{{ old('length_cm', $product->length_cm) }}"></label>
                <label>Width (cm) <input type="number" step="0.1" min="0" name="width_cm" value="{{ old('width_cm', $product->width_cm) }}"></label>
                <label>Height (cm) <input type="number" step="0.1" min="0" name="height_cm" value="{{ old('height_cm', $product->height_cm) }}"></label>
                <label>Ships in
                    <select name="packaging">
                        <option value="">—</option>
                        <option value="flyer" @selected(old('packaging', $product->packaging) === 'flyer')>Flyer (poly bag)</option>
                        <option value="box" @selected(old('packaging', $product->packaging) === 'box')>Carton box</option>
                    </select>
                </label>
            </div>
            @if($product->exists)
            <div class="rate-test" data-rate-test="{{ route('admin.products.rate', $product) }}">
                <b>Test the delivery charge</b> <small class="muted">— save first, then check what Delhivery charges for this product</small>
                <div class="row-inline">
                    <input type="text" inputmode="numeric" maxlength="6" placeholder="Customer PIN" data-rate-pin>
                    <input type="number" min="1" max="20" value="1" style="width:70px" title="Quantity" data-rate-qty>
                    <button type="button" class="btn small" data-rate-go>Check</button>
                </div>
                <div class="muted small" data-rate-out></div>
            </div>
            @endif
            <label>Short description <textarea name="short_description" rows="3">{{ old('short_description', $product->short_description) }}</textarea></label>
            <label>Description (HTML allowed) <textarea name="description" id="f-description" rows="10">{{ old('description', $product->description) }}</textarea></label>
        </section>

        <section class="card">
            <h2>Images</h2>
            <p class="muted">Pick from the media library or upload new ones. Drag to reorder (or use the arrows); the first image is the main image in listings, Google Shopping and social shares.</p>
            <input type="hidden" name="gallery_present" value="1">
            <div class="gallery" id="gallery">
                @foreach (array_values($gallery) as $i => $g)
                    @include('admin.products._gallery', ['i' => $i, 'g' => $g])
                @endforeach
            </div>
            <p class="muted" data-gallery-empty @if ($gallery) hidden @endif>No images yet.</p>
            <template id="tpl-gallery">@include('admin.products._gallery', ['i' => '__i__', 'g' => []])</template>
            <div class="row-inline">
                <button type="button" class="btn" data-media-pick data-multiple="1" data-folder="products" data-gallery-add>+ Add images</button>
                <details class="fallback-upload">
                    <summary class="muted">or upload straight from this computer</summary>
                    <input type="file" name="new_images[]" accept="image/*" multiple>
                </details>
            </div>
        </section>

        <section class="card">
            <h2>Customer personalisation</h2>
            <p class="muted">What the customer uploads or types before adding to cart. A "Photo upload" field sets how many photos this product needs.</p>
            @php $presetJson = collect(\App\Support\Personalisation::presets())->map(fn ($p) => ['label' => $p[0], 'fields' => collect($p[1])->map(fn ($f) => array_merge($f, ['slots' => implode("\n", $f['slots'] ?? [])]))->all()]); @endphp
            <div class="preset-bar" data-presets='@json($presetJson)'>
                <span class="muted small">Quick setup:</span>
                @foreach ($presetJson as $key => $p)<button type="button" class="chip-btn" data-preset="{{ $key }}">{{ $p['label'] }}</button>@endforeach
            </div>
            <div id="fields" data-rows>
                @foreach (array_values($fields) as $i => $f)
                    @include('admin.products._field', ['i' => $i, 'f' => $f, 'fieldTypes' => $fieldTypes])
                @endforeach
            </div>
            <template id="tpl-field">@include('admin.products._field', ['i' => '__i__', 'f' => ['type' => 'text', 'required' => false, 'min_files' => 1, 'max_files' => 1], 'fieldTypes' => $fieldTypes])</template>
            <button type="button" class="btn ghost" data-add-row="fields" data-tpl="tpl-field">+ Add field</button>
        </section>

        <section class="card" id="variation-box">
            <h2>Options &amp; variations</h2>
            <p class="muted">Attributes like Size or Frame colour. For a variable product, each variation has its own price.</p>
            <div id="attrs" data-rows>
                @foreach (array_values($attrs) as $i => $a)
                    @include('admin.products._attr', ['i' => $i, 'a' => $a])
                @endforeach
            </div>
            <template id="tpl-attr">@include('admin.products._attr', ['i' => '__i__', 'a' => []])</template>
            <button type="button" class="btn ghost" data-add-row="attrs" data-tpl="tpl-attr">+ Add attribute</button>

            <h3>Variations</h3>
            <p class="muted">Options are written <code>Size=A4; Frame=Black</code>.</p>
            <div class="tbl-wrap">
            <table class="tbl compact">
                <thead><tr><th>Options</th><th>Price</th><th>Sale</th><th>SKU</th><th>In stock</th><th>Stock</th><th>Image</th><th></th></tr></thead>
                <tbody id="variants" data-rows>
                    @foreach (array_values($variants) as $i => $v)
                        @include('admin.products._variant', ['i' => $i, 'v' => $v])
                    @endforeach
                </tbody>
            </table>
            </div>
            <template id="tpl-variant">@include('admin.products._variant', ['i' => '__i__', 'v' => ['in_stock' => true]])</template>
            <button type="button" class="btn ghost" data-add-row="variants" data-tpl="tpl-variant">+ Add variation</button>
            <button type="button" class="btn ghost" id="gen-variants">Generate all combinations</button>
        </section>

        <section class="card">
            <h2>SEO</h2>
            <div class="serp" aria-label="Google preview">
                <div class="serp-url">{{ parse_url(url('/'), PHP_URL_HOST) }} › product › <span id="serp-slug">{{ $product->slug }}</span></div>
                <div class="serp-title" id="serp-title">{{ $product->meta_title ?: $product->name }}</div>
                <div class="serp-desc" id="serp-desc">{{ $product->meta_description ?: \Illuminate\Support\Str::limit(strip_tags((string) $product->short_description), 155) }}</div>
            </div>
            <label>Meta title <small class="counter" data-for="f-meta-title" data-max="60"></small>
                <input name="meta_title" id="f-meta-title" value="{{ old('meta_title', $product->meta_title) }}" maxlength="255" placeholder="Leave blank to generate from the name and price">
            </label>
            <label>Meta description <small class="counter" data-for="f-meta-desc" data-max="160"></small>
                <textarea name="meta_description" id="f-meta-desc" rows="3" maxlength="320">{{ old('meta_description', $product->meta_description) }}</textarea>
            </label>
            <div class="row2">
                <label>Focus keyword <input name="focus_keyword" id="f-focus" value="{{ old('focus_keyword', $product->focus_keyword) }}" placeholder="e.g. personalised photo frame"></label>
                <label>Search keywords (comma separated) <input name="meta_keywords" value="{{ old('meta_keywords', $product->meta_keywords) }}" placeholder="also found by on-site search"></label>
            </div>
            <div id="focus-checks" class="checks"></div>
            <label>Canonical URL <small class="muted">only if this product duplicates another page</small>
                <input type="url" name="canonical_url" value="{{ old('canonical_url', $product->canonical_url) }}">
            </label>
            <label class="check"><input type="checkbox" name="noindex" value="1" @checked(old('noindex', $product->noindex))> Hide from Google (noindex)</label>

            <h3>FAQs <small class="muted">shown on the page and as FAQ rich results</small></h3>
            <div id="faqs" data-rows>
                @foreach (array_values($faqs) as $i => $f)
                    @include('admin.products._faq', ['i' => $i, 'f' => $f])
                @endforeach
            </div>
            <template id="tpl-faq">@include('admin.products._faq', ['i' => '__i__', 'f' => []])</template>
            <button type="button" class="btn ghost" data-add-row="faqs" data-tpl="tpl-faq">+ Add FAQ</button>
        </section>
    </div>

    <aside>
        <section class="card sticky">
            <h2>Publish</h2>
            <label class="check"><input type="checkbox" name="is_active" value="1" @checked(old('is_active', $product->is_active))> Active (visible in store)</label>
            <label class="check"><input type="checkbox" name="in_stock" value="1" @checked(old('in_stock', $product->in_stock))> In stock</label>
            <label>Stock quantity <small class="muted">blank = made to order (never runs out). Variable products: set stock per variation.</small>
                <input type="number" name="stock_qty" min="0" step="1" value="{{ old('stock_qty', $product->stock_qty) }}" placeholder="Not counted"></label>
            @if ($product->exists)
                @php $moves = \App\Models\StockMovement::where('product_id', $product->id)->latest('id')->take(8)->get(); @endphp
                @if ($moves->isNotEmpty())
                    <details class="stock-log"><summary>Stock history</summary>
                        <ul>@foreach ($moves as $m)<li><b>{{ $m->change > 0 ? '+'.$m->change : $m->change }}</b> → {{ $m->balance }} · {{ $m->reason }}@if ($m->order_id) <a href="{{ route('admin.orders.show', $m->order_id) }}">order</a>@endif <span class="muted small">{{ $m->created_at->format('d M') }}</span></li>@endforeach</ul>
                    </details>
                @endif
            @endif
            <label class="check"><input type="checkbox" name="is_featured" value="1" @checked(old('is_featured', $product->is_featured))> Featured on home page</label>
            <button class="btn full">{{ $product->exists ? 'Save product' : 'Create product' }}</button>
            @if ($product->exists)
                @php $sc = \App\Http\Controllers\Admin\SeoController::score($product); @endphp
                <div class="seo-box">
                    <div>SEO score <span class="score {{ $sc['score'] >= 80 ? 'good' : ($sc['score'] >= 50 ? 'mid' : 'bad') }}">{{ $sc['score'] }}</span></div>
                    @if ($sc['issues'])<ul>@foreach ($sc['issues'] as $is)<li>{{ $is }}</li>@endforeach</ul>@endif
                </div>
            @endif
        </section>
        <section class="card">
            <h2>Categories</h2>
            <label>Primary category
                <select name="primary_category_id">
                    <option value="">—</option>
                    @foreach ($categories as $c)
                        <option value="{{ $c->id }}" @selected(old('primary_category_id', $product->primary_category_id) == $c->id)>{{ str_repeat('— ', substr_count($c->path, '/')) }}{{ $c->name }}</option>
                    @endforeach
                </select>
            </label>
            <div class="cat-list">
                @foreach ($categories as $c)
                    <label class="check" style="padding-left: {{ substr_count($c->path, '/') * 14 }}px"><input type="checkbox" name="categories[]" value="{{ $c->id }}" @checked(in_array($c->id, $selectedCats))> {{ $c->name }}</label>
                @endforeach
            </div>
        </section>
        <section class="card">
            <h2>Google Shopping</h2>
            <label>Brand <input name="brand" value="{{ old('brand', $product->brand) }}"></label>
            <label>GTIN / barcode <input name="gtin" value="{{ old('gtin', $product->gtin) }}"></label>
            <label>HSN code <small class="muted">for the GST invoice</small><input name="hsn_code" value="{{ old('hsn_code', $product->hsn_code) }}" maxlength="12"></label>
            <label>GST rate % <small class="muted">blank = store default ({{ setting('invoice.gst_rate') }}%)</small><input type="number" step="0.01" min="0" max="28" name="gst_rate" value="{{ old('gst_rate', $product->gst_rate) }}"></label>
            <label>Google product category <input name="google_category" value="{{ old('google_category', $product->google_category) }}" placeholder="e.g. Home & Garden > Decor > Picture Frames"></label>
        </section>
    </aside>
    </div>
</form>

@if ($product->exists)
    <form method="post" action="{{ route('admin.products.destroy', $product) }}" class="card danger-zone">
        @csrf @method('DELETE')
        <button class="btn danger" data-confirm="Delete this product permanently? Its URL will stop working — consider hiding it or adding a redirect instead.">Delete product</button>
    </form>
@endif
@endsection
