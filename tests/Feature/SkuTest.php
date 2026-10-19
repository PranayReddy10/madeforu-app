<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Services\Sku;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SkuTest extends TestCase
{
    use RefreshDatabase;

    private function product(string $name, ?Category $cat = null, ?string $sku = null): Product
    {
        return Product::create(['name' => $name, 'slug' => Str::slug($name), 'type' => 'simple', 'price' => 100,
            'is_active' => true, 'in_stock' => true, 'primary_category_id' => $cat?->id, 'sku' => $sku]);
    }

    public function test_new_products_and_options_get_a_sku_automatically(): void
    {
        $mag = Category::create(['name' => 'Magnets', 'slug' => 'magnets', 'path' => 'magnets']);
        $a = $this->product('Round Fridge Magnet', $mag);
        $b = $this->product('Square Photo Magnet', $mag);
        $mug = $this->product('Magic Photo Mug');
        $combo = $this->product('Coffee Mug with Round Photo Magnet Combo', $mag);
        $kept = $this->product('Old Woo Product', $mag, 'WOO-123');

        $this->assertSame(['MFU-MAG-001', 'MFU-MAG-002', 'MFU-MUG-001', 'MFU-CMB-001', 'WOO-123'],
            [$a->fresh()->sku, $b->fresh()->sku, $mug->fresh()->sku, $combo->fresh()->sku, $kept->fresh()->sku]);

        $v = $a->variants()->create(['options' => ['Shape' => 'Heart', 'Pack' => 'Pack of 6'], 'price' => 120]);
        $this->assertSame('MFU-MAG-001-HRT-P6', $v->fresh()->sku);
        $this->assertSame('A4', Sku::optionCode('A4'));
        $this->assertSame('TS', Sku::optionCode('Two sides'));
    }

    public function test_button_fills_missing_and_duplicate_skus(): void
    {
        $p = $this->product('Photo Keychain', null, 'DUP-1');
        $q = $this->product('Another Keychain', null, 'dup-1');           // same SKU, different case
        $p->variants()->create(['options' => ['Side' => 'One side'], 'price' => 100, 'sku' => 'DUP-1']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.products.index'))->assertSee('Create 2 missing SKUs');
        $this->post(route('admin.products.skus'))->assertSessionHas('status');
        $this->assertSame('DUP-1', $p->fresh()->sku);
        $this->assertSame('MFU-KEY-001', $q->fresh()->sku);
        $this->assertSame('DUP-1-OS', $p->variants()->first()->sku);
        $this->assertSame(0, Sku::missingCount());
        $this->get(route('admin.products.index'))->assertDontSee('missing SKU');
    }
}
