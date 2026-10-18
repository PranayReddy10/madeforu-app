<?php

namespace Tests\Feature;

use App\Mail\CartReminder;
use App\Mail\LowStock;
use App\Models\AbandonedCart;
use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\StockMovement;
use App\Models\User;
use App\Services\Stock;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockAndCartRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
        'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'];

    private Product $mug;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        $this->mug = Product::where('slug', 'magic-photo-mug')->first();
    }

    private function addMug(int $qty = 1)
    {
        $photo = $this->mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');

        return $this->postJson(route('cart.add', $this->mug), ['qty' => $qty, 'custom' => [$photo->id => [$token]]]);
    }

    public function test_stock_limits_cart_and_counts_down_on_order(): void
    {
        $this->mug->update(['stock_qty' => 4]);
        Setting::put(['low_stock_threshold' => '3']);

        $this->addMug(5)->assertStatus(422)->assertJsonValidationErrors('qty');
        $this->addMug(2)->assertOk();
        $this->get($this->mug->url())->assertDontSee('Only 4 left');   // 4 is above the warning level

        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertRedirect();
        $this->assertSame(2, $this->mug->fresh()->stock_qty);
        $this->assertSame(-2, StockMovement::where('reason', 'order')->value('change'));
        Mail::assertSent(LowStock::class);                           // crossed the threshold (4 -> 2)
        $this->get($this->mug->url())->assertSee('Only 2 left');

        // Cancelling in admin puts it back
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $order = Order::firstOrFail();
        $this->actingAs($admin)->put(route('admin.orders.update', $order), ['status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->assertSame(4, $this->mug->fresh()->stock_qty);
        $this->actingAs($admin)->put(route('admin.orders.update', $order), ['status' => 'cancelled', 'payment_status' => 'unpaid']);
        $this->assertSame(4, $this->mug->fresh()->stock_qty);        // only once
    }

    public function test_sold_out_while_in_cart_is_caught_at_checkout(): void
    {
        $this->mug->update(['stock_qty' => 3]);
        $this->addMug(2)->assertOk();
        $this->mug->update(['stock_qty' => 0]);                      // someone else bought them
        $this->assertFalse($this->mug->fresh()->in_stock);
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertRedirect(route('cart'))->assertSessionHas('error');
        $this->assertSame(0, Order::count());
    }

    public function test_made_to_order_products_are_not_limited(): void
    {
        $this->assertNull($this->mug->stock_qty);
        $this->addMug(40)->assertOk();
    }

    public function test_variant_stock_and_admin_adjustment_log(): void
    {
        $frame = Product::where('slug', 'personalised-wooden-photo-frame')->with('variants')->first();
        $v = $frame->variants->first();
        $v->update(['stock_qty' => 0]);
        $this->assertFalse($v->fresh()->in_stock);
        Stock::logAdjustment($frame, $v, null, 10);
        $this->assertSame('Stock counting started', StockMovement::latest('id')->value('note'));
    }

    public function test_cart_is_saved_reminded_and_recovered(): void
    {
        Coupon::create(['code' => 'COMEBACK10', 'type' => 'percent', 'value' => 10]);
        Setting::put(['abandoned.first_hours' => '1', 'abandoned.second_hours' => '24', 'abandoned.coupon' => 'COMEBACK10']);
        $this->addMug()->assertOk();
        $this->postJson(route('checkout.capture'), ['name' => 'Asha Rao', 'email' => 'asha@example.com'])->assertJson(['saved' => true]);
        $cart = AbandonedCart::firstOrFail();
        $this->assertEquals(449, (float) $cart->value);

        // Too early: nothing
        $this->artisan('store:cart-reminders');
        Mail::assertNothingSent();

        $this->travel(61)->minutes();
        $this->artisan('store:cart-reminders');
        Mail::assertSent(CartReminder::class, fn ($m) => $m->number === 1 && $m->coupon === null && $m->hasTo('asha@example.com'));
        $this->artisan('store:cart-reminders');
        Mail::assertSent(CartReminder::class, 1);                      // not again within the hour

        $this->travel(24)->hours();
        $this->artisan('store:cart-reminders');
        Mail::assertSent(CartReminder::class, fn ($m) => $m->number === 2 && $m->coupon === 'COMEBACK10');

        // The link restores the cart (photos included) in a fresh browser, with the coupon
        $this->withCookie(config('session.cookie'), str_repeat('n', 40));
        $this->get(route('cart.recover', $cart->token).'?coupon=COMEBACK10')->assertRedirect(route('checkout'));
        $this->get('/checkout')->assertOk()->assertSee('asha@example.com', false)->assertSee('COMEBACK10');
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertSame('COMEBACK10', $order->coupon_code);
        $this->assertSame($order->id, $cart->fresh()->recovered_order_id);
        $this->assertSame('abandoned_cart', $order->utm['utm_source']);
    }

    public function test_ordering_from_another_device_stops_reminders_and_unsubscribe_works(): void
    {
        $this->addMug()->assertOk();
        $this->postJson(route('checkout.capture'), ['phone' => '98765 43210', 'email' => 'asha@example.com']);
        $cart = AbandonedCart::firstOrFail();
        Order::create(['number' => 'MFU1', 'status' => 'confirmed', 'payment_method' => 'cod', 'subtotal' => 1, 'total' => 1] + self::ADDRESS);
        $this->travel(2)->hours();
        $this->artisan('store:cart-reminders');
        Mail::assertNothingSent();
        $this->assertNotNull($cart->fresh()->recovered_at);

        $other = AbandonedCart::create(['token' => (string) Str::uuid(), 'session_id' => 'x', 'email' => 'b@example.com', 'lines' => $cart->lines, 'value' => 449]);
        $this->get(route('cart.unsubscribe', $other->token))->assertRedirect();
        $this->travel(3)->hours();
        $this->artisan('store:cart-reminders');
        Mail::assertNothingSent();
    }

    public function test_admin_abandoned_carts_page(): void
    {
        $this->addMug()->assertOk();
        $this->postJson(route('checkout.capture'), ['name' => 'Asha', 'phone' => '9876543210']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.abandoned.index'))->assertOk()->assertSee('Magic Photo Mug')->assertSee('https://wa.me/919876543210', false);
    }
}
