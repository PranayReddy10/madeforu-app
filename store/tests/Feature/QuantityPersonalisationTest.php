<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Services\Cart;
use App\Support\Personalisation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class QuantityPersonalisationTest extends TestCase
{
    use RefreshDatabase;

    private Product $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        $this->useBrowserSession();
        $this->mug = Product::where('slug', 'magic-photo-mug')->first();
        Personalisation::apply($this->mug, Personalisation::presets()['photo1_name'][1]);   // 1 photo + a name
        $this->mug->load('customizationFields');
    }

    private function photo(): string
    {
        return $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('p.jpg')])->json('token');
    }

    public function test_product_page_offers_a_box_per_item(): void
    {
        $this->get($this->mug->url())->assertOk()
            ->assertSee('data-each-choice', false)->assertSee('Different for each')
            ->assertSee('items[__N__][custom]', false);
    }

    public function test_different_for_each_adds_one_line_per_item(): void
    {
        [$photo, $name] = [$this->mug->customizationFields[0], $this->mug->customizationFields[1]];
        $this->postJson(route('cart.add', $this->mug), [
            'qty' => 3, 'custom_each' => 1,
            'custom' => [$photo->id => [$this->photo()], $name->id => 'Amma'],
            'items' => [1 => ['custom' => [$photo->id => [$this->photo()], $name->id => 'Nanna']], 2 => ['custom' => [$photo->id => [$this->photo()], $name->id => 'Akka']]],
        ])->assertOk()->assertJson(['count' => 3]);

        $lines = app(Cart::class)->lines();
        $this->assertCount(3, $lines);
        $this->assertSame(['Amma', 'Nanna', 'Akka'], $lines->map(fn ($l) => collect($l->details)->firstWhere('type', 'text')->value)->all());
        $this->assertTrue($lines->every(fn ($l) => $l->qty === 1 && $l->details[0]->uploads->count() === 1));
    }

    public function test_a_missing_photo_on_one_item_adds_nothing(): void
    {
        [$photo, $name] = [$this->mug->customizationFields[0], $this->mug->customizationFields[1]];
        $res = $this->postJson(route('cart.add', $this->mug), [
            'qty' => 2, 'custom_each' => 1,
            'custom' => [$photo->id => [$this->photo()], $name->id => 'Amma'],
            'items' => [1 => ['custom' => [$name->id => 'Nanna']]],
        ])->assertUnprocessable();

        $this->assertStringStartsWith('Item 2:', collect($res->json('errors'))->flatten()->first());
        $this->assertCount(0, app(Cart::class)->lines());
    }

    public function test_same_for_all_keeps_one_line_with_the_quantity(): void
    {
        [$photo, $name] = [$this->mug->customizationFields[0], $this->mug->customizationFields[1]];
        $this->postJson(route('cart.add', $this->mug), ['qty' => 3, 'custom_each' => 0, 'custom' => [$photo->id => [$this->photo()], $name->id => 'Team']])
            ->assertOk()->assertJson(['count' => 3]);

        $lines = app(Cart::class)->lines();
        $this->assertCount(1, $lines);
        $this->assertSame(3, $lines[0]->qty);
    }

    public function test_cart_and_checkout_show_each_items_photos(): void
    {
        [$photo, $name] = [$this->mug->customizationFields[0], $this->mug->customizationFields[1]];
        $this->postJson(route('cart.add', $this->mug), [
            'qty' => 2, 'custom_each' => 1,
            'custom' => [$photo->id => [$this->photo()], $name->id => 'Amma'],
            'items' => [1 => ['custom' => [$photo->id => [$this->photo()], $name->id => 'Nanna']]],
        ])->assertOk();

        $html = $this->get('/cart')->assertOk()->assertSee('Amma')->assertSee('Nanna')->getContent();
        preg_match_all('/<img src="([^"]+signature=[^"]+)"/', $html, $m);
        $this->assertCount(2, $m[1]);                       // one photo per item, as signed links
        $this->get('/checkout')->assertOk()->assertSee('line-photos compact', false);

        // Logging in changes the session; the photos must still load.
        $this->actingAs(\App\Models\User::factory()->create());
        $this->get(html_entity_decode($m[1][0]))->assertOk();
    }
}
