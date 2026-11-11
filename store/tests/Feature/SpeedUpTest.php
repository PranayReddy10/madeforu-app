<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SpeedUpTest extends TestCase
{
    use RefreshDatabase;

    public function test_speed_up_makes_small_webp_copies_used_by_product_cards(): void
    {
        if (! function_exists('imagewebp')) {
            $this->markTestSkipped('GD without WebP');
        }
        $this->seed(DemoSeeder::class);
        Storage::fake('public');
        config(['store.media_disk' => 'public']);
        $p = Product::where('slug', 'magic-photo-mug')->first();
        Storage::disk('public')->put('products/mug.jpg', UploadedFile::fake()->image('mug.jpg', 1200, 1200)->getContent());
        $p->images()->delete();
        $p->images()->create(['path' => 'products/mug.jpg', 'alt' => 'Mug', 'position' => 0]);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/')->assertSee('Make the website faster')->assertSee('1 product photo still needs');

        $this->post(route('admin.speed'))->assertRedirect()->assertSessionHas('status');
        Storage::disk('public')->assertExists(['products/mug.webp', 'products/mug-480.webp']);
        [$w] = getimagesize(Storage::disk('public')->path('products/mug-480.webp'));
        $this->assertSame(480, $w);

        $this->get('/admin/')->assertDontSee('still needs a small');
        $this->get('/shop/')->assertSee('mug-480.webp 480w', false)->assertSee('mug.webp 1600w', false);
    }
}
