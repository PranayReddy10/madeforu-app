<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Cart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CartEachChoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        $this->useBrowserSession();
    }

    public function test_personalised_line_asks_same_or_different_and_prefills_choices(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $box = $mug->customizationFields->firstWhere('type', 'select');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token], $box->id => 'Premium gift box']]);
        $line = app(Cart::class)->lines()->first();

        $html = $this->get('/cart/')->assertOk()->getContent();
        $this->assertStringContainsString('data-each-ask', $html);
        $this->assertStringContainsString('Same for all 2', $html);
        $this->assertStringContainsString('from_line='.$line->id, $html);

        // "Different for each" opens the product with the gift box already chosen.
        $this->call('GET', '/product/magic-photo-mug/', ['from_line' => $line->id])->assertOk()
            ->assertSee('Personalising <b>one more', false)
            ->assertSee('value="Premium gift box" selected', false);
        $this->get('/product/magic-photo-mug/')->assertDontSee('Personalising <b>one more', false)
            ->assertDontSee('value="Premium gift box" selected', false);
    }
}
