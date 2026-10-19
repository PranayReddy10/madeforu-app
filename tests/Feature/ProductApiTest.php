<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    public function test_products_list_has_name_price_images_and_more_but_never_costs(): void
    {
        Product::query()->update(['cost_price' => 12.34]);
        $res = $this->getJson('/api/v1/products?per_page=2')->assertOk()
            ->assertJsonStructure(['data' => [['id', 'sku', 'name', 'url', 'price', 'regular_price', 'image', 'images', 'categories', 'variants', 'personalisation', 'in_stock']], 'meta' => ['total', 'last_page'], 'links' => ['next']])
            ->assertHeader('Access-Control-Allow-Origin', '*');
        $this->assertCount(2, $res->json('data'));
        $this->assertStringNotContainsString('cost', $res->getContent());
        $this->assertStringNotContainsString('12.34', $res->getContent());

        $frame = $this->getJson('/api/v1/products/personalised-wooden-photo-frame')->assertOk()->json('data');
        $this->assertSame('Personalised Wooden Photo Frame', $frame['name']);
        $this->assertNotEmpty($frame['variants']);
        $this->getJson('/api/v1/products/'.$frame['id'])->assertOk()->assertJsonPath('data.slug', 'personalised-wooden-photo-frame');
        $this->getJson('/api/v1/products/no-such-thing')->assertNotFound()->assertJsonPath('error', 'Product not found.');
    }

    public function test_filters_and_categories(): void
    {
        $this->getJson('/api/v1/products?category=photo-frames')->assertOk()->assertJsonPath('data.0.slug', 'personalised-wooden-photo-frame');
        $this->getJson('/api/v1/products?q=mug')->assertOk()->assertJsonFragment(['slug' => 'magic-photo-mug']);
        $this->getJson('/api/v1/products?updated_since=2999-01-01')->assertOk()->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products?per_page=500')->assertStatus(422);
        Product::where('slug', 'magic-photo-mug')->update(['is_active' => false]);
        $this->getJson('/api/v1/products/magic-photo-mug')->assertNotFound();

        $cats = collect($this->getJson('/api/v1/categories')->assertOk()->json('data'))->keyBy('slug');
        $this->assertGreaterThanOrEqual(1, $cats['photo-frames']['products']);   // counts its sub-category's products
    }

    public function test_optional_api_key(): void
    {
        $this->getJson('/api/v1')->assertOk()->assertJsonPath('auth', 'open');
        Setting::put(['api.key' => 'secret-key-123']);
        $this->getJson('/api/v1/products')->assertStatus(401);
        $this->getJson('/api/v1/products?key=wrong')->assertStatus(401);
        $this->getJson('/api/v1/products?key=secret-key-123')->assertOk();
        $this->getJson('/api/v1/products', ['X-Api-Key' => 'secret-key-123'])->assertOk();
    }
}
