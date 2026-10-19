<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\Cart;
use App\Services\Delhivery;
use App\Services\Parcel;
use App\Services\Sku;
use App\Services\Stock;
use App\Support\Personalisation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = Product::with(['images', 'variants', 'primaryCategory', 'customizationFields']);
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }
        if ($c = $request->query('category')) {
            $q->whereHas('categories', fn ($w) => $w->where('categories.id', $c));
        }
        if ($request->filled('active')) {
            $q->where('is_active', $request->query('active') === '1');
        }
        $low = Stock::lowThreshold();
        match ($request->query('stock')) {
            'low' => $q->where(fn ($w) => $w->where(fn ($s) => $s->whereNotNull('stock_qty')->where('stock_qty', '<=', $low))
                ->orWhereHas('variants', fn ($v) => $v->whereNotNull('stock_qty')->where('stock_qty', '<=', $low))),
            'out' => $q->where('in_stock', false),
            'counted' => $q->where(fn ($w) => $w->whereNotNull('stock_qty')->orWhereHas('variants', fn ($v) => $v->whereNotNull('stock_qty'))),
            default => null,
        };

        return view('admin.products.index', [
            'products' => $q->latest()->paginate(30)->withQueryString(),
            'categories' => Category::orderBy('path')->get(),
            'missingSkus' => Sku::missingCount(),
        ]);
    }

    /** One click: an MFU-MAG-007 style SKU for every product and option that has none. */
    public function fillSkus()
    {
        $n = Sku::fillMissing();

        return back()->with('status', "Created {$n} ".($n === 1 ? 'SKU' : 'SKUs').'. SKUs you had already set were kept.');
    }

    /** Apply a personalisation preset, or copy one product's setup, to many products at once. */
    public function bulkPersonalisation(Request $request)
    {
        $data = $request->validate([
            'source' => 'required|string',
            'ids' => 'nullable|array',
            'ids.*' => 'integer',
            'category' => 'nullable|integer',
        ]);
        if (str_starts_with($data['source'], 'product:')) {
            $from = Product::with('customizationFields')->findOrFail((int) substr($data['source'], 8));
            $fields = Personalisation::fromProduct($from);
            $label = 'the setup of “'.$from->name.'”';
        } else {
            $preset = Personalisation::presets()[$data['source']] ?? abort(422, 'Unknown preset.');
            [$label, $fields] = [$preset[0], $preset[1]];
        }
        $targets = ! empty($data['category'])
            ? Product::whereHas('categories', fn ($q) => $q->where('categories.id', $data['category']))->get()
            : Product::whereIn('id', $data['ids'] ?? [])->get();
        if ($targets->isEmpty()) {
            return back()->with('error', 'Tick some products (or choose a category) first.');
        }
        foreach ($targets as $p) {
            if (! isset($from) || ! $p->is($from)) {
                Personalisation::apply($p, $fields);
            }
        }

        return back()->with('status', 'Applied '.$label.' to '.$targets->count().' '.Str::plural('product', $targets->count()).'.');
    }

    public function create()
    {
        return view('admin.products.form', ['product' => new Product(['type' => 'simple', 'is_active' => true, 'in_stock' => true, 'production_days' => 3, 'brand' => 'MadeForU']), 'categories' => Category::orderBy('path')->get()]);
    }

    public function store(Request $request)
    {
        $product = new Product;
        $this->save($request, $product);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product created.');
    }

    public function edit(Product $product)
    {
        $product->load(['images', 'variants', 'productAttributes', 'customizationFields', 'categories']);

        return view('admin.products.form', ['product' => $product, 'categories' => Category::orderBy('path')->get()]);
    }

    /** Admin tester: the parcel this product makes and what Delhivery charges for it to a PIN. */
    public function deliveryRate(Request $request, Product $product, Delhivery $delhivery)
    {
        $pin = preg_replace('/\D/', '', (string) $request->query('pin'));
        $qty = max(1, min(20, $request->integer('qty', 1)));
        $parcel = Parcel::of([[$product, $qty]]);
        $price = (float) ($product->isOnSale() ? $product->sale_price : $product->price) * $qty;
        $prepaid = $delhivery->rateDetails($pin, $parcel->chargeableGrams());
        $cod = $delhivery->rateDetails($pin, $parcel->chargeableGrams(), true, $price);
        $quote = Cart::shippingQuote([[$product, $qty]], $pin, true, $price);

        return response()->json([
            'parcel' => ['actual_g' => $parcel->grams, 'volumetric_g' => $parcel->volumetricGrams(), 'chargeable_g' => $parcel->chargeableGrams(),
                'size_cm' => $parcel->length ? [$parcel->length, $parcel->width, $parcel->height] : null, 'packaging' => $parcel->packaging],
            'prepaid' => $prepaid, 'cod' => $cod,
            'customer_pays' => $quote ? ['shipping' => $quote['shipping'], 'cod_charge' => $quote['cod']] : null,
        ]);
    }

    public function update(Request $request, Product $product)
    {
        $this->save($request, $product);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product saved.');
    }

    public function destroy(Product $product)
    {
        // Image files stay in the media library; other products may use them.
        $product->delete();

        return redirect()->route('admin.products.index')->with('status', 'Product deleted.');
    }

    public function uploadImages(Request $request, Product $product)
    {
        $request->validate(['images' => 'required|array', 'images.*' => 'image|max:10240']);
        $this->storeImages($request->file('images'), $product);

        return back()->with('status', 'Images uploaded.');
    }

    public function deleteImage(ProductImage $image)
    {
        $image->delete();

        return back()->with('status', 'Image removed from the product (it stays in the media library).');
    }

    private function save(Request $request, Product $product): void
    {
        if (! $request->filled('slug')) {
            $request->merge(['slug' => Str::slug((string) $request->input('name'))]);
        } else {
            $request->merge(['slug' => Str::slug((string) $request->input('slug'))]);
        }

        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('products', 'slug')->ignore($product->id)],
            'sku' => 'nullable|string|max:100',
            'type' => 'required|in:simple,variable',
            'price' => 'required|numeric|min:0',
            'sale_price' => 'nullable|numeric|min:0',
            'short_description' => 'nullable|string',
            'description' => 'nullable|string',
            'categories' => 'nullable|array',
            'categories.*' => 'integer|exists:categories,id',
            'primary_category_id' => 'nullable|integer|exists:categories,id',
            'production_days' => 'nullable|integer|min:0|max:60',
            'weight_g' => 'nullable|integer|min:0|max:30000',
            'weight_kg' => 'nullable|numeric|min:0|max:30',
            'length_cm' => 'nullable|numeric|min:0|max:150',
            'width_cm' => 'nullable|numeric|min:0|max:150',
            'height_cm' => 'nullable|numeric|min:0|max:150',
            'packaging' => 'nullable|in:flyer,box',
            'stock_qty' => 'nullable|integer|min:0',
            'variants.*.stock_qty' => 'nullable|integer|min:0',
            'brand' => 'nullable|string|max:100',
            'gtin' => 'nullable|string|max:50',
            'hsn_code' => 'nullable|string|max:12',
            'gst_rate' => 'nullable|numeric|min:0|max:28',
            'google_category' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
            'focus_keyword' => 'nullable|string|max:255',
            'meta_keywords' => 'nullable|string|max:500',
            'canonical_url' => 'nullable|url|max:255',
            'faqs' => 'nullable|array',
            'faqs.*.q' => 'nullable|string|max:500',
            'faqs.*.a' => 'nullable|string|max:3000',
            'gallery' => 'nullable|array|max:40',
            'gallery.*.path' => ['required', 'string', 'max:1000', function ($attr, $value, $fail) use ($product) {
                if (! Media::acceptable($value) && ! ($product->exists && $product->images()->where('path', $value)->exists())) {
                    $fail('One of the images is not in the media library.');
                }
            }],
            'gallery.*.alt' => 'nullable|string|max:255',
            'images' => 'nullable|array',
            'images.*.alt' => 'nullable|string|max:255',
            'images.*.position' => 'nullable|integer',
            'new_images' => 'nullable|array',
            'new_images.*' => 'image|max:10240',
            'attrs' => 'nullable|array',
            'attrs.*.name' => 'nullable|string|max:100',
            'attrs.*.values' => 'nullable|string|max:2000',
            'variants' => 'nullable|array',
            'variants.*.id' => 'nullable|integer',
            'variants.*.options' => 'nullable|string|max:1000',
            'variants.*.price' => 'nullable|numeric|min:0',
            'variants.*.sale_price' => 'nullable|numeric|min:0',
            'variants.*.sku' => 'nullable|string|max:100',
            'variants.*.image' => ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) use ($product) {
                if (! Media::acceptable($value) && ! ($product->exists && $product->variants()->where('image', $value)->exists())) {
                    $fail('A variation image is not in the media library.');
                }
            }],
            'fields' => 'nullable|array',
            'fields.*.id' => 'nullable|integer',
            'fields.*.type' => 'nullable|in:images,text,textarea,select,choice,color,date',
            'fields.*.choices' => 'nullable|array|max:30',
            'fields.*.choices.*.label' => 'nullable|string|max:60',
            'fields.*.choices.*.price' => 'nullable|numeric|min:0',
            'fields.*.choices.*.image' => 'nullable|string|max:500',
            'fields.*.choices.*.photos' => 'nullable|integer|min:1|max:50',
            'fields.*.label' => 'nullable|string|max:255',
            'fields.*.help' => 'nullable|string|max:255',
            'fields.*.min_files' => 'nullable|integer|min:0|max:50',
            'fields.*.max_files' => 'nullable|integer|min:0|max:50',
            'fields.*.max_length' => 'nullable|integer|min:1|max:5000',
            'fields.*.options' => 'nullable|string|max:3000',
            'fields.*.extra_price' => 'nullable|numeric|min:0',
            'fields.*.position' => 'nullable|integer',
            'fields.*.slots' => 'nullable|string|max:1000',
        ], ['weight_g.max' => 'Weight is in grams — for 1.5 kg enter 1500.', 'length_cm.max' => 'Sizes are in cm — for 120 mm enter 12.', 'width_cm.max' => 'Sizes are in cm — for 120 mm enter 12.', 'height_cm.max' => 'Sizes are in cm — for 120 mm enter 12.']);

        $stockBefore = $product->stock_qty;
        DB::transaction(function () use ($request, $product, $data) {
            $sale = $data['sale_price'] ?? null;
            $product->fill([
                'name' => $data['name'],
                'slug' => $data['slug'],
                'sku' => $data['sku'] ?? null,
                'type' => $data['type'],
                'price' => $data['price'],
                'sale_price' => $sale !== null && (float) $sale > 0 ? $sale : null,
                'short_description' => $data['short_description'] ?? null,
                'description' => $data['description'] ?? null,
                'primary_category_id' => $data['primary_category_id'] ?? ($data['categories'][0] ?? null),
                'is_active' => $request->boolean('is_active'),
                'is_featured' => $request->boolean('is_featured'),
                'in_stock' => $request->boolean('in_stock'),
                'noindex' => $request->boolean('noindex'),
                'production_days' => $data['production_days'] ?? 3,
                'weight_kg' => $request->has('weight_g') ? ($data['weight_g'] ? $data['weight_g'] / 1000 : null) : ($data['weight_kg'] ?? null),
                'length_cm' => $data['length_cm'] ?? null,
                'width_cm' => $data['width_cm'] ?? null,
                'height_cm' => $data['height_cm'] ?? null,
                'packaging' => $data['packaging'] ?? null,
                'stock_qty' => isset($data['stock_qty']) && $data['stock_qty'] !== '' ? (int) $data['stock_qty'] : null,
                'brand' => ($data['brand'] ?? null) ?: 'MadeForU',
                'gtin' => $data['gtin'] ?? null,
                'hsn_code' => $data['hsn_code'] ?? null,
                'gst_rate' => isset($data['gst_rate']) && $data['gst_rate'] !== '' ? $data['gst_rate'] : null,
                'google_category' => $data['google_category'] ?? null,
                'meta_title' => $data['meta_title'] ?? null,
                'meta_description' => $data['meta_description'] ?? null,
                'focus_keyword' => $data['focus_keyword'] ?? null,
                'meta_keywords' => $data['meta_keywords'] ?? null,
                'canonical_url' => $data['canonical_url'] ?? null,
                'faqs' => collect($data['faqs'] ?? [])->filter(fn ($f) => trim((string) ($f['q'] ?? '')) !== '' && trim((string) ($f['a'] ?? '')) !== '')
                    ->map(fn ($f) => ['q' => trim($f['q']), 'a' => trim($f['a'])])->values()->all() ?: null,
            ]);
            $product->save();

            $cats = $data['categories'] ?? [];
            if ($product->primary_category_id && ! in_array($product->primary_category_id, $cats)) {
                $cats[] = $product->primary_category_id;
            }
            $product->categories()->sync($cats);

            if ($request->boolean('gallery_present')) {
                // Gallery from the media picker: the posted order is the display order.
                $existing = $product->images()->get()->groupBy('path');
                $keepIds = [];
                foreach (array_values($data['gallery'] ?? []) as $i => $g) {
                    $alt = trim((string) ($g['alt'] ?? '')) ?: null;
                    $img = $existing->get($g['path'])?->shift();
                    if ($img) {
                        $img->update(['alt' => $alt, 'position' => $i]);
                    } else {
                        $img = $product->images()->create(['path' => $g['path'], 'alt' => $alt ?: $product->name, 'position' => $i]);
                    }
                    $keepIds[] = $img->id;
                    Media::register($g['path'], 'products', $alt);
                }
                $product->images()->whereNotIn('id', $keepIds ?: [0])->delete();
            } else {
                // Older form: alt + order per existing image
                foreach ($data['images'] ?? [] as $id => $img) {
                    $product->images()->whereKey($id)->update(['alt' => $img['alt'] ?? null, 'position' => (int) ($img['position'] ?? 0)]);
                }
            }

            // Attributes: replaced wholesale
            $product->productAttributes()->delete();
            foreach (array_values($data['attrs'] ?? []) as $i => $a) {
                $name = trim((string) ($a['name'] ?? ''));
                $values = array_values(array_filter(array_map('trim', explode(',', (string) ($a['values'] ?? ''))), 'strlen'));
                if ($name === '' || ! $values) {
                    continue;
                }
                $product->productAttributes()->create(['name' => $name, 'values' => $values, 'is_variation' => true, 'position' => $i]);
            }

            // Variants: update by id, create new, delete removed
            $keep = [];
            $rawVariants = array_values($request->input('variants', []));
            foreach (array_values($data['variants'] ?? []) as $i => $v) {
                $options = self::parseOptions((string) ($v['options'] ?? ''));
                if (! $options || ! isset($v['price']) || $v['price'] === '') {
                    continue;
                }
                $vSale = $v['sale_price'] ?? null;
                $row = [
                    'options' => $options,
                    'price' => $v['price'],
                    'sale_price' => $vSale !== null && $vSale !== '' && (float) $vSale > 0 ? $vSale : null,
                    'sku' => $v['sku'] ?? null,
                    'in_stock' => ! empty($rawVariants[$i]['in_stock']),
                    'stock_qty' => isset($v['stock_qty']) && $v['stock_qty'] !== '' ? (int) $v['stock_qty'] : null,
                    'image' => $v['image'] ?? null,
                    'position' => $i,
                ];
                if ($row['image']) {
                    Media::register($row['image'], 'products');
                }
                $existing = ! empty($v['id']) ? $product->variants()->whereKey($v['id'])->first() : null;
                if ($existing) {
                    Stock::logAdjustment($product, $existing, $existing->stock_qty, $row['stock_qty']);
                    $existing->update($row);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = $product->variants()->create($row)->id;
                }
            }
            $product->variants()->whereNotIn('id', $keep ?: [0])->delete();

            // Customisation fields: update by id so carts holding answers keyed by field id keep them
            $keepF = [];
            // An option like "Two sides" (2 photos) can need more photo boxes, and labels, than the field's own max.
            $optionPhotos = (int) collect($data['fields'] ?? [])->flatMap(fn ($f) => [
                ...array_map(fn ($line) => self::photoCount(explode('|', $line)[2] ?? null), preg_split('/\r?\n/', (string) ($f['options'] ?? ''))),
                ...array_map(fn ($c) => self::photoCount($c['photos'] ?? null), $f['choices'] ?? []),
            ])->filter()->max();
            foreach (array_values($data['fields'] ?? []) as $i => $f) {
                $label = trim((string) ($f['label'] ?? ''));
                if ($label === '' || empty($f['type'])) {
                    continue;
                }
                $raw = array_values($request->input('fields', []))[$i] ?? [];
                $options = null;
                if ($f['type'] === 'select') {
                    $options = collect(preg_split('/\r?\n/', (string) ($f['options'] ?? '')))->map('trim')->filter()
                        ->map(function ($line) {
                            [$l, $p, $n] = array_pad(explode('|', $line, 3), 3, '');

                            return array_filter(['label' => trim($l), 'price' => (float) $p, 'photos' => self::photoCount($n)], fn ($v) => $v !== null);
                        })->values()->all();
                }
                if ($f['type'] === 'choice') {
                    $options = collect($f['choices'] ?? [])->filter(fn ($c) => trim((string) ($c['label'] ?? '')) !== '')
                        ->map(fn ($c) => array_filter(['label' => trim($c['label']), 'price' => (float) ($c['price'] ?? 0), 'image' => ($c['image'] ?? '') ?: null, 'photos' => self::photoCount($c['photos'] ?? null)], fn ($v, $k) => $v !== null || $k === 'image', ARRAY_FILTER_USE_BOTH))->values()->all();
                    foreach ($options as $o) {
                        if ($o['image']) {
                            Media::register($o['image'], 'options');
                        }
                    }
                }
                $min = (int) ($f['min_files'] ?? 1);
                $max = max($min, (int) ($f['max_files'] ?? 1));
                if ($f['type'] === 'images') {
                    // One label per photo box, e.g. "His photo" / "Her photo"
                    $slots = collect(preg_split('/\r?\n/', (string) ($f['slots'] ?? '')))->map('trim')->filter()->take(max($max, $optionPhotos))->values();
                    $options = $slots->isNotEmpty() ? $slots->map(fn ($l) => ['label' => $l])->all() : null;
                }
                $row = [
                    'type' => $f['type'],
                    'label' => $label,
                    'help' => $f['help'] ?? null,
                    'required' => ! empty($raw['required']),
                    'min_files' => $f['type'] === 'images' ? max(1, $min) : 1,
                    'max_files' => $f['type'] === 'images' ? max(1, $max) : 1,
                    'max_length' => $f['max_length'] ?? null,
                    'options' => $options,
                    'extra_price' => $f['extra_price'] ?? 0,
                    'position' => (int) ($f['position'] ?? $i),
                ];
                $existing = ! empty($f['id']) ? $product->customizationFields()->whereKey($f['id'])->first() : null;
                if ($existing) {
                    $existing->update($row);
                    $keepF[] = $existing->id;
                } else {
                    $keepF[] = $product->customizationFields()->create($row)->id;
                }
            }
            $product->customizationFields()->whereNotIn('id', $keepF ?: [0])->delete();

            if ($request->hasFile('new_images')) {
                $this->storeImages($request->file('new_images'), $product);
            }
        });
        Stock::logAdjustment($product, null, $stockBefore, $product->fresh()->stock_qty);
    }

    /** "Size=A4; Frame=Black" → ['Size' => 'A4', 'Frame' => 'Black'] */
    /** "How many photos this option needs" (e.g. Two sides → 2); blank = the photo field's own count. */
    private static function photoCount(mixed $v): ?int
    {
        $n = (int) trim((string) $v);

        return $n >= 1 && $n <= 50 ? $n : null;
    }

    public static function parseOptions(string $s): array
    {
        $out = [];
        foreach (array_filter(array_map('trim', explode(';', $s))) as $pair) {
            [$k, $v] = array_pad(array_map('trim', explode('=', $pair, 2)), 2, '');
            if ($k !== '' && $v !== '') {
                $out[$k] = $v;
            }
        }

        return $out;
    }

    /** Direct uploads (fallback to the picker): saved to the media library, then added after the existing images. */
    private function storeImages(array $files, Product $product): void
    {
        $pos = (int) $product->images()->max('position');
        foreach ($files as $file) {
            $media = MediaController::storeUpload($file, 'products', $product->name);
            $product->images()->create(['path' => $media->path, 'alt' => $product->name, 'position' => ++$pos]);
        }
    }
}
