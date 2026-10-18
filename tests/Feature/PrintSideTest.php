<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\Cart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PrintSideTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        // "Print side": One side = 1 photo, Two sides = 2 photos and ₹30 more.
        $this->p = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs($admin)->put(route('admin.products.update', $this->p), [
            'name' => $this->p->name, 'slug' => $this->p->slug, 'type' => 'simple', 'price' => 300, 'is_active' => 1, 'in_stock' => 1,
            'fields' => [
                ['type' => 'select', 'label' => 'Print side', 'required' => 1, 'position' => 0, 'options' => "One side|0|1\nTwo sides|30|2"],
                ['type' => 'images', 'label' => 'Upload your photos', 'min_files' => 1, 'max_files' => 1, 'required' => 1, 'position' => 1, 'slots' => "Front side\nBack side"],
            ],
        ])->assertSessionHasNoErrors();
        auth()->logout();
        $this->p->refresh()->load('customizationFields');
    }

    private function photos(int $n): array
    {
        return collect(range(1, $n))->map(fn ($i) => $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image("p{$i}.jpg")])->json('token'))->all();
    }

    public function test_admin_saves_photo_counts_and_the_page_offers_up_to_two_boxes(): void
    {
        [$side, $photo] = [$this->p->customizationFields[0], $this->p->customizationFields[1]];
        $this->assertEquals([['label' => 'One side', 'price' => 0.0, 'photos' => 1], ['label' => 'Two sides', 'price' => 30.0, 'photos' => 2]], $side->options);
        $this->assertSame($photo->id, $this->p->photoFieldId());
        $this->assertSame(2, $this->p->photoSlots($photo));

        $html = $this->get($this->p->url())->assertOk()->assertSee('data-photo-target', false)->assertSee('data-photos="2"', false)
            ->assertSee('Two sides (+₹30)', false)->assertSee('Back side')->getContent();
        $this->assertSame(2, substr_count($html, 'data-slot') / 2);   // main box + item template, 2 boxes each

        $this->actingAs(User::where('is_admin', true)->first())->get(route('admin.products.edit', $this->p))->assertSee("Two sides|30|2");
    }

    public function test_one_side_takes_one_photo_and_two_sides_take_two(): void
    {
        [$side, $photo] = [$this->p->customizationFields[0], $this->p->customizationFields[1]];
        $this->useBrowserSession();
        [$a, $b] = $this->photos(2);

        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$side->id => 'One side', $photo->id => [$a, $b]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['custom.'.$photo->id => 'at most 1 photos']);
        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$side->id => 'Two sides', $photo->id => [$a]]])
            ->assertUnprocessable()->assertJsonValidationErrors(['custom.'.$photo->id => 'Please upload 2 photos']);

        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$side->id => 'One side', $photo->id => [$a]]])->assertOk();
        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$side->id => 'Two sides', $photo->id => [$a, $b]]])->assertOk();

        $lines = app(Cart::class)->lines();
        $this->assertEquals([300, 330], $lines->pluck('unit')->map(fn ($u) => (float) $u)->sort()->values()->all());
    }
}
