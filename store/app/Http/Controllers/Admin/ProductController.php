<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Services\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function index(Request $request)
    {
        $q = Product::with(['images', 'variants', 'primaryCategory']);
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('sku', 'like', "%{$s}%"));
        }
        if ($c = $request->query('category')) {
            $q->whereHas('categories', fn ($w) => $w->where('categories.id', $c));
        }
        if ($request->filled('active')) {
            $q->where('is_active', $request->query('active') === '1');
        }

        return view('admin.products.index', [
            'products' => $q->latest()->paginate(30)->withQueryString(),
            'categories' => Category::orderBy('path')->get(),
        ]);
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

    public function update(Request $request, Product $product)
    {
        $this->save($request, $product);

        return redirect()->route('admin.products.edit', $product)->with('status', 'Product saved.');
    }

    public function destroy(Product $product)
    {
        foreach ($product->images as $img) {
            $this->deleteFile($img->path);
        }
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
        $this->deleteFile($image->path);
        $image->delete();

        return back()->with('status', 'Image deleted.');
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
            'weight_kg' => 'nullable|numeric|min:0',
            'brand' => 'nullable|string|max:100',
            'gtin' => 'nullable|string|max:50',
            'google_category' => 'nullable|string|max:255',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
            'focus_keyword' => 'nullable|string|max:255',
            'meta_keywords' => 'nullable|string|max:500',
            'canonical_url' => 'nullable|url|max:255',
            'faqs' => 'nullable|array',
            'faqs.*.q' => 'nullable|string|max:500',
            'faqs.*.a' => 'nullable|string|max:3000',
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
            'variants.*.image' => 'nullable|string|max:1000',
            'fields' => 'nullable|array',
            'fields.*.id' => 'nullable|integer',
            'fields.*.type' => 'nullable|in:images,text,textarea,select,color,date',
            'fields.*.label' => 'nullable|string|max:255',
            'fields.*.help' => 'nullable|string|max:255',
            'fields.*.min_files' => 'nullable|integer|min:0|max:50',
            'fields.*.max_files' => 'nullable|integer|min:0|max:50',
            'fields.*.max_length' => 'nullable|integer|min:1|max:5000',
            'fields.*.options' => 'nullable|string|max:3000',
            'fields.*.extra_price' => 'nullable|numeric|min:0',
            'fields.*.position' => 'nullable|integer',
        ]);

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
                'weight_kg' => $data['weight_kg'] ?? null,
                'brand' => ($data['brand'] ?? null) ?: 'MadeForU',
                'gtin' => $data['gtin'] ?? null,
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

            // Existing images: alt + order
            foreach ($data['images'] ?? [] as $id => $img) {
                $product->images()->whereKey($id)->update(['alt' => $img['alt'] ?? null, 'position' => (int) ($img['position'] ?? 0)]);
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
                    'image' => $v['image'] ?? null,
                    'position' => $i,
                ];
                $existing = ! empty($v['id']) ? $product->variants()->whereKey($v['id'])->first() : null;
                if ($existing) {
                    $existing->update($row);
                    $keep[] = $existing->id;
                } else {
                    $keep[] = $product->variants()->create($row)->id;
                }
            }
            $product->variants()->whereNotIn('id', $keep ?: [0])->delete();

            // Customisation fields: update by id so carts holding answers keyed by field id keep them
            $keepF = [];
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
                            [$l, $p] = array_pad(explode('|', $line, 2), 2, 0);

                            return ['label' => trim($l), 'price' => (float) $p];
                        })->values()->all();
                }
                $min = (int) ($f['min_files'] ?? 1);
                $max = max($min, (int) ($f['max_files'] ?? 1));
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
    }

    /** "Size=A4; Frame=Black" → ['Size' => 'A4', 'Frame' => 'Black'] */
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

    private function storeImages(array $files, Product $product): void
    {
        $pos = (int) $product->images()->max('position');
        foreach ($files as $file) {
            $name = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: Str::random(8);
            $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
            $dir = 'products/'.$product->slug;
            $fname = $name.'.'.$ext;
            if (Storage::disk('public')->exists($dir.'/'.$fname)) {
                $fname = $name.'-'.Str::random(5).'.'.$ext;
            }
            $path = $file->storeAs($dir, $fname, 'public');
            if (in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
                ImageTools::toWebp(Storage::disk('public')->path($path), Storage::disk('public')->path(preg_replace('/\.\w+$/', '.webp', $path)));
            }
            $product->images()->create(['path' => $path, 'alt' => $product->name, 'position' => ++$pos]);
        }
    }

    private function deleteFile(?string $path): void
    {
        if ($path && ! preg_match('#^https?://#', $path)) {
            Storage::disk('public')->delete([$path, preg_replace('/\.\w+$/', '.webp', $path)]);
        }
    }
}
