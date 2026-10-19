<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class SalesBoostTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
    }

    /** One magic mug (₹449). */
    private function addMug(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
    }

    public function test_cart_lists_public_offers_and_applies_one_with_a_tap(): void
    {
        Coupon::create(['code' => 'SHOWME', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'show_on_cart' => true]);
        Coupon::create(['code' => 'BIGGER', 'type' => 'fixed', 'value' => 100, 'is_active' => true, 'show_on_cart' => true, 'min_subtotal' => 999]);
        Coupon::create(['code' => 'SECRET', 'type' => 'percent', 'value' => 50, 'is_active' => true]);
        Coupon::create(['code' => 'ENDED', 'type' => 'percent', 'value' => 5, 'is_active' => true, 'show_on_cart' => true, 'expires_at' => now()->subDay()]);
        $this->addMug();

        $this->get('/cart/')->assertSee('Available offers')->assertSee('SHOWME')->assertSee('10% off on your order')
            ->assertSee('BIGGER')->assertSee('Add ₹550 more to use it')
            ->assertDontSee('SECRET')->assertDontSee('ENDED');

        $this->post(route('cart.coupon'), ['code' => 'SHOWME']);
        $this->get('/cart/')->assertSee('Coupon <b>SHOWME</b> applied', false)->assertDontSee('Available offers');
    }

    public function test_online_payment_discount_is_shown_and_saved_on_the_order(): void
    {
        Setting::put(['razorpay.enabled' => '1', 'razorpay.key' => 'rzp_test_x', 'razorpay.secret' => 's', 'prepaid.amount' => '20', 'prepaid.percent' => '5', 'prepaid.max' => '40']);
        $this->addMug();

        // 5% of 449 = 22.45 (more than ₹20, under the ₹40 cap)
        $this->get('/cart/')->assertSee('Online payment discount')->assertSee('−₹22.45', false);
        $this->get(route('checkout'))->assertSee('Save ₹22.45');

        $res = $this->post(route('checkout.place'), [
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'payment_method' => 'cod',
        ]);
        $res->assertSessionHasNoErrors();
        $cod = Order::latest('id')->first();
        $this->assertEquals(0, (float) $cod->prepaid_discount, 'COD gets no online discount');
    }

    public function test_online_discount_lands_in_the_order_discount(): void
    {
        Setting::put(['prepaid.amount' => '30']);
        $this->assertSame(0.0, \App\Services\Cart::prepaidDiscount(500), 'no online gateway, no discount');
        Setting::put(['razorpay.enabled' => '1', 'razorpay.key' => 'rzp_test_x', 'razorpay.secret' => 's']);
        $this->assertSame(30.0, \App\Services\Cart::prepaidDiscount(500));

        $this->addMug();
        $t = app(\App\Services\Cart::class)->totals(null, 'razorpay');
        $this->assertEquals(30, $t['prepaid_discount']);
        $this->assertEquals(449 - 30 + 60, $t['total']);
        $this->assertEquals(0, app(\App\Services\Cart::class)->totals(null, 'cod')['prepaid_discount']);
    }

    public function test_product_page_free_delivery_nudge(): void
    {
        Setting::put(['free_shipping_over' => '999']);
        $this->get('/product/magic-photo-mug/')->assertSee('Add ₹550 more for free delivery');
        $this->addMug();
        $this->addMug();
        $this->get('/product/magic-photo-mug/')->assertSee('Add this and your whole order ships free');
        $this->addMug();
        $this->get('/product/magic-photo-mug/')->assertSee('Your order already ships free');
    }

    public function test_often_bought_together_from_past_orders(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $frame = Product::where('slug', 'personalised-wooden-photo-frame')->first();
        foreach ([1, 2] as $n) {
            $o = Order::create(['number' => 'T'.$n, 'name' => 'A', 'email' => 'a@x.in', 'phone' => '9876543210', 'address_line1' => 'x', 'city' => 'x', 'state' => 'x', 'pincode' => '500001',
                'subtotal' => 1, 'total' => 1, 'status' => 'confirmed', 'payment_method' => 'cod']);
            foreach ([$mug, $frame] as $p) {
                $o->items()->create(['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'unit_price' => 1, 'line_total' => 1]);
            }
        }
        $this->get('/product/magic-photo-mug/')->assertSee('Often bought together')->assertSee($frame->name);
    }
}
