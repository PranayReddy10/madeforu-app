<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\Redirect;
use App\Services\WooImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class WooImporterTest extends TestCase
{
    use RefreshDatabase;

    public function test_imports_store_api_catalogue_keeping_slugs_prices_and_variations(): void
    {
        Storage::fake('public');
        $site = 'https://madeforu.co.in';
        $img = fn ($n) => ['id' => $n, 'src' => "$site/wp-content/uploads/2025/01/frame-$n.jpg", 'alt' => '', 'name' => "frame-$n"];
        Http::fake([
            "$site/wp-json/wc/store/v1/products/categories*" => Http::response([
                ['id' => 10, 'name' => 'Photo Frames', 'slug' => 'photo-frames', 'parent' => 0, 'description' => '', 'image' => null, 'count' => 2, 'permalink' => "$site/product-category/photo-frames/"],
                ['id' => 11, 'name' => 'Wall &amp; Desk', 'slug' => 'wall-desk', 'parent' => 10, 'description' => '', 'image' => null, 'count' => 1, 'permalink' => "$site/product-category/photo-frames/wall-desk/"],
            ], 200, ['X-WP-TotalPages' => 1]),
            "$site/wp-json/wc/store/v1/products/reviews*" => Http::response([
                ['id' => 1, 'product_id' => 101, 'reviewer' => 'Meera', 'review' => '<p>Lovely!</p>', 'rating' => 5, 'date_created' => '2025-03-01T10:00:00'],
            ]),
            "$site/wp-json/wc/store/v1/products/201" => Http::response(['id' => 201, 'sku' => 'FR-A4', 'prices' => ['price' => '59900', 'regular_price' => '69900', 'sale_price' => '59900', 'currency_minor_unit' => 2], 'is_in_stock' => true, 'images' => []]),
            "$site/wp-json/wc/store/v1/products/202" => Http::response(['id' => 202, 'sku' => 'FR-A3', 'prices' => ['price' => '89900', 'regular_price' => '89900', 'sale_price' => '89900', 'currency_minor_unit' => 2], 'is_in_stock' => false, 'images' => []]),
            "$site/wp-json/wc/store/v1/products*" => Http::response([
                [
                    'id' => 101, 'name' => 'Couple Collage of 4 Photos', 'slug' => 'couple-collage', 'type' => 'simple', 'sku' => 'COL4',
                    'permalink' => "$site/product/couple-collage/", 'short_description' => '<p>Four pics</p>', 'description' => '<p>Long</p>',
                    'prices' => ['price' => '79900', 'regular_price' => '99900', 'sale_price' => '79900', 'currency_minor_unit' => 2],
                    'images' => [$img(1), $img(2)], 'categories' => [['id' => 10, 'name' => 'Photo Frames', 'slug' => 'photo-frames']],
                    'attributes' => [], 'variations' => [], 'is_in_stock' => true, 'average_rating' => '5.00', 'review_count' => 1, 'tags' => [['name' => 'collage']],
                ],
                [
                    'id' => 102, 'name' => 'Wooden Frame', 'slug' => 'wooden-frame', 'type' => 'variable', 'sku' => '',
                    'permalink' => "$site/shop/wooden-frame/", 'short_description' => '', 'description' => '',
                    'prices' => ['price' => '59900', 'regular_price' => '69900', 'sale_price' => '59900', 'currency_minor_unit' => 2],
                    'images' => [$img(3)], 'categories' => [['id' => 11, 'name' => 'Wall', 'slug' => 'wall-desk']],
                    'attributes' => [['id' => 1, 'name' => 'Size', 'taxonomy' => 'pa_size', 'has_variations' => true, 'terms' => [['id' => 5, 'name' => 'A4 (8x12)', 'slug' => 'a4-8x12'], ['id' => 6, 'name' => 'A3', 'slug' => 'a3']]]],
                    'variations' => [['id' => 201, 'attributes' => [['name' => 'Size', 'value' => 'a4-8x12']]], ['id' => 202, 'attributes' => [['name' => 'Size', 'value' => 'a3']]]],
                    'is_in_stock' => true,
                ],
            ], 200, ['X-WP-TotalPages' => 1]),
            "$site/wp-json/wp/v2/product*" => Http::response([['id' => 101, 'yoast_head_json' => ['title' => 'Couple Collage Frame | MadeForU', 'description' => 'Yoast desc']]]),
            "$site/wp-json/wp/v2/pages*" => Http::response([
                ['slug' => 'about-us', 'title' => ['rendered' => 'About Us'], 'content' => ['rendered' => '<p class="x">Hello [vc_row]world</p>'], 'link' => "$site/about-us/"],
                ['slug' => 'cart', 'title' => ['rendered' => 'Cart'], 'content' => ['rendered' => ''], 'link' => "$site/cart/"],
            ]),
            "$site/wp-content/*" => Http::response('fake-jpeg-bytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $stats = (new WooImporter($site))->run();

        $this->assertSame(2, $stats['products']);
        $this->assertSame('photo-frames/wall-desk', Category::where('woo_id', 11)->value('path'));
        $this->assertSame('Wall & Desk', Category::where('woo_id', 11)->value('name'));

        $collage = Product::where('woo_id', 101)->first();
        $this->assertSame('couple-collage', $collage->slug);
        $this->assertEquals(999, (float) $collage->price);
        $this->assertEquals(799, (float) $collage->sale_price);
        $this->assertSame('Couple Collage Frame | MadeForU', $collage->meta_title);
        $this->assertSame(2, $collage->images()->count());
        Storage::disk('public')->assertExists('products/couple-collage/frame-1.jpg');
        // "of 4 Photos" -> upload field asking for exactly four photos
        $field = $collage->customizationFields()->where('type', 'images')->first();
        $this->assertSame([4, 4], [$field->min_files, $field->max_files]);
        $this->assertSame(1, $collage->reviews()->count());

        $frame = Product::where('woo_id', 102)->with('variants')->first();
        $this->assertSame('variable', $frame->type);
        $this->assertSame(['Size' => 'A4 (8x12)'], $frame->variants[0]->options);
        $this->assertEquals(599, $frame->variants[0]->currentPrice());
        $this->assertFalse($frame->variants[1]->in_stock);
        // Non-standard permalink base kept working via a redirect
        $this->assertSame('/product/wooden-frame/', Redirect::where('from_path', '/shop/wooden-frame')->value('to_path'));

        $this->get('/product/couple-collage/')->assertOk();
        $this->get('/product-category/photo-frames/wall-desk/')->assertOk()->assertSee('Wooden Frame');
        $this->get('/about-us/')->assertOk()->assertSee('Hello world');
        $this->assertDatabaseMissing('pages', ['slug' => 'cart']);

        // Running again updates in place, no duplicates
        (new WooImporter($site))->run();
        $this->assertSame(2, Product::count());
        $this->assertSame(2, $frame->variants()->count());
        $this->assertSame(1, $collage->customizationFields()->where('type', 'images')->count());
    }

    public function test_imports_woocommerce_csv_export(): void
    {
        Storage::fake('public');
        Http::fake(['*' => Http::response('img', 200, ['Content-Type' => 'image/png'])]);
        $csv = tempnam(sys_get_temp_dir(), 'woo');
        $rows = [
            ['ID', 'Type', 'SKU', 'Name', 'Published', 'Is featured?', 'Short description', 'Description', 'In stock?', 'Weight (kg)', 'Sale price', 'Regular price', 'Categories', 'Tags', 'Images', 'Parent', 'Attribute 1 name', 'Attribute 1 value(s)'],
            ['5', 'variable', 'MUG', 'Photo Mug', '1', '1', '', 'Desc', '1', '0.4', '', '', 'Drinkware > Mugs', 'mug', 'https://x.test/a.png', '', 'Colour', 'White, Black'],
            ['6', 'variation', 'MUG-W', 'Photo Mug - White', '1', '0', '', '', '1', '', '299', '349', '', '', '', 'id:5', 'Colour', 'White'],
            ['7', 'variation', 'MUG-B', 'Photo Mug - Black', '1', '0', '', '', '0', '', '', '399', '', '', '', 'id:5', 'Colour', 'Black'],
        ];
        $fh = fopen($csv, 'w');
        foreach ($rows as $r) {
            fputcsv($fh, $r);
        }
        fclose($fh);

        (new WooImporter('https://x.test'))->importCsv($csv);
        $p = Product::with('variants')->firstOrFail();
        $this->assertSame('drinkware/mugs', $p->primaryCategory->path);
        $this->assertCount(2, $p->variants);
        $this->assertEquals(299, $p->variants->firstWhere('sku', 'MUG-W')->currentPrice());
        $this->assertFalse($p->variants->firstWhere('sku', 'MUG-B')->in_stock);
    }

    public function test_rest_api_keys_import_drafts_hidden(): void
    {
        $site = 'https://shop.test';
        Http::fake([
            "$site/wp-json/wc/v3/products/categories*" => Http::response([
                ['id' => 10, 'name' => 'Mugs', 'slug' => 'mugs', 'parent' => 0, 'description' => '', 'image' => null, 'count' => 2],
            ]),
            "$site/wp-json/wc/v3/products/7/variations*" => Http::response([
                ['id' => 71, 'sku' => 'M-11', 'price' => '299', 'regular_price' => '349', 'sale_price' => '299', 'status' => 'publish', 'stock_status' => 'instock', 'image' => null, 'attributes' => [['name' => 'Size', 'option' => '11 oz']]],
                ['id' => 72, 'sku' => '', 'price' => '', 'regular_price' => '', 'sale_price' => '', 'status' => 'publish', 'stock_status' => 'instock', 'image' => null, 'attributes' => [['name' => 'Size', 'option' => '']]],
            ]),
            "$site/wp-json/wc/v3/products/reviews*" => Http::response([]),
            "$site/wp-json/wc/v3/products*" => Http::response([
                ['id' => 5, 'name' => 'Photo Mug', 'slug' => 'photo-mug', 'status' => 'publish', 'type' => 'simple', 'sku' => 'MUG', 'permalink' => "$site/product/photo-mug/",
                    'price' => '249', 'regular_price' => '299', 'sale_price' => '249', 'stock_status' => 'instock', 'images' => [], 'categories' => [['id' => 10]], 'tags' => [], 'attributes' => [],
                    'meta_data' => [['key' => '_yoast_wpseo_title', 'value' => 'Photo Mug | MadeForU'], ['key' => '_yoast_wpseo_metadesc', 'value' => '%%excerpt%%']]],
                ['id' => 6, 'name' => 'Photo Mug', 'slug' => '', 'status' => 'draft', 'type' => 'simple', 'sku' => '', 'permalink' => "$site/?post_type=product&p=6",
                    'price' => '199', 'regular_price' => '199', 'sale_price' => '', 'stock_status' => 'instock', 'images' => [], 'categories' => [['id' => 10]], 'tags' => [], 'attributes' => []],
                ['id' => 7, 'name' => 'Big Mug', 'slug' => 'big-mug', 'status' => 'private', 'type' => 'variable', 'sku' => '', 'permalink' => "$site/product/big-mug/",
                    'price' => '299', 'regular_price' => '', 'sale_price' => '', 'stock_status' => 'instock', 'images' => [], 'categories' => [['id' => 10]], 'tags' => [],
                    'attributes' => [['name' => 'Size', 'variation' => true, 'options' => ['11 oz']]]],
                ['id' => 8, 'name' => 'Old', 'slug' => 'old', 'status' => 'trash', 'type' => 'simple', 'price' => '1', 'images' => [], 'categories' => [], 'attributes' => []],
            ]),
        ]);

        $stats = (new WooImporter($site, 'ck_test', 'cs_test'))->run(withPages: false);

        $this->assertSame(3, $stats['products']);
        Http::assertSent(fn ($r) => str_contains($r->url(), '/wc/v3/products') && $r->hasHeader('Authorization'));
        $published = Product::where('woo_id', 5)->first();
        $this->assertTrue($published->is_active);
        $this->assertSame('Photo Mug | MadeForU', $published->meta_title);
        $this->assertNull($published->meta_description);   // Yoast template variables are not copied
        $this->assertEquals(249, (float) $published->sale_price);

        $draft = Product::where('woo_id', 6)->first();
        $this->assertFalse($draft->is_active);
        $this->assertSame('photo-mug-2', $draft->slug);
        $this->get('/product/photo-mug-2/')->assertNotFound();

        $big = Product::where('woo_id', 7)->with('variants')->first();
        $this->assertFalse($big->is_active);
        $this->assertCount(1, $big->variants);               // the empty "any" variation is skipped
        $this->assertSame(['Size' => '11 oz'], $big->variants[0]->options);
        $this->assertEquals(299, $big->variants[0]->currentPrice());
        $this->assertNull(Product::where('woo_id', 8)->first());
    }
}
