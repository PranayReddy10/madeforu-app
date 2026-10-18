<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\Cart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ShapeChoiceTest extends TestCase
{
    use RefreshDatabase;

    private Product $p;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        // Admin sets up "pick a shape, then add your photo".
        $this->p = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs($admin)->put(route('admin.products.update', $this->p), [
            'name' => $this->p->name, 'slug' => $this->p->slug, 'type' => 'simple', 'price' => 300, 'sale_price' => '', 'is_active' => 1, 'in_stock' => 1,
            'fields' => [
                ['type' => 'choice', 'label' => 'Choose a shape', 'required' => 1, 'position' => 0, 'choices' => [
                    ['label' => 'Heart', 'price' => 20, 'image' => 'https://cdn.example.com/heart.png'],
                    ['label' => 'Round', 'price' => 0, 'image' => 'https://cdn.example.com/round.png'],
                    ['label' => '', 'price' => 0],   // empty rows are dropped
                ]],
                ['type' => 'images', 'label' => 'Upload your photo', 'min_files' => 1, 'max_files' => 1, 'required' => 1, 'position' => 1],
            ],
        ])->assertSessionHasNoErrors();
        auth()->logout();
        $this->p->refresh()->load('customizationFields');
    }

    public function test_admin_saves_picture_options(): void
    {
        $shape = $this->p->customizationFields[0];
        $this->assertSame('choice', $shape->type);
        $this->assertEquals([['label' => 'Heart', 'price' => 20.0, 'image' => 'https://cdn.example.com/heart.png'], ['label' => 'Round', 'price' => 0.0, 'image' => 'https://cdn.example.com/round.png']], $shape->options);
    }

    public function test_customer_picks_a_shape_and_it_follows_the_order(): void
    {
        $this->get($this->p->url())->assertOk()->assertSee('choice-tiles', false)
            ->assertSee('https://cdn.example.com/heart.png')->assertSee('+₹20', false);

        [$shape, $photo] = [$this->p->customizationFields[0], $this->p->customizationFields[1]];
        $this->useBrowserSession();
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');

        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$shape->id => 'Triangle', $photo->id => [$token]]])
            ->assertUnprocessable();                                                    // not one of the options
        $this->postJson(route('cart.add', $this->p), ['qty' => 1, 'custom' => [$shape->id => 'Heart', $photo->id => [$token]]])->assertOk();

        $line = app(Cart::class)->lines()->first();
        $this->assertEquals(320, $line->unit);                                          // 300 + Heart's 20
        $this->get('/cart')->assertSee('Choose a shape')->assertSee('Heart')->assertSee('https://cdn.example.com/heart.png');

        $this->post(route('checkout.place'), [
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'payment_method' => 'cod',
        ])->assertRedirect();
        $item = Order::latest('id')->first()->items->first();
        $choice = collect($item->customization)->firstWhere('label', 'Choose a shape');
        $this->assertSame(['Heart', 'https://cdn.example.com/heart.png'], [$choice['value'], $choice['image']]);

        $admin = User::where('is_admin', true)->first();
        $this->actingAs($admin)->get(route('admin.orders.show', $item->order))->assertSee('Heart')->assertSee('https://cdn.example.com/heart.png');
        $this->get(route('admin.orders.production', $item->order))->assertSee('https://cdn.example.com/heart.png');
    }
}
