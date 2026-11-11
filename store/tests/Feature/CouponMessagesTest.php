<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class CouponMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        $this->useBrowserSession();
    }

    /** One magic mug (₹449) in the cart. */
    private function addMug(int $qty = 1): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['qty' => $qty, 'custom' => [$photo->id => [$token]]]);
    }

    private function applyAndSee(string $code, string $text): void
    {
        $this->from('/cart/')->post(route('cart.coupon'), ['code' => $code])->assertRedirect('/cart/');
        $this->get('/cart/')->assertOk()->assertSee($text, false)->assertSee('class="coupon-msg err"', false);
    }

    public function test_each_reason_gets_its_own_message_beside_the_box(): void
    {
        $this->addMug();
        $this->applyAndSee('nosuch', '“NOSUCH” isn’t a valid coupon code');
        $this->applyAndSee('', 'Please type a coupon code first.');

        Coupon::create(['code' => 'OFF5', 'type' => 'percent', 'value' => 5, 'is_active' => false]);
        $this->applyAndSee('off5', 'OFF5 isn’t active right now.');

        Coupon::create(['code' => 'SOON', 'type' => 'percent', 'value' => 5, 'is_active' => true, 'starts_at' => now()->addDays(3)]);
        $this->applyAndSee('soon', 'SOON starts on '.now()->addDays(3)->format('j M'));

        Coupon::create(['code' => 'OLD', 'type' => 'percent', 'value' => 5, 'is_active' => true, 'expires_at' => now()->subDay()]);
        $this->applyAndSee('old', 'OLD expired on');

        Coupon::create(['code' => 'GONE', 'type' => 'percent', 'value' => 5, 'is_active' => true, 'usage_limit' => 2, 'used' => 2]);
        $this->applyAndSee('gone', 'GONE has been fully used');
    }

    public function test_below_minimum_coupon_waits_and_switches_on_by_itself(): void
    {
        Coupon::create(['code' => 'DIWALI10', 'type' => 'percent', 'value' => 10, 'is_active' => true, 'min_subtotal' => 999]);
        $this->addMug();
        $this->from('/cart/')->post(route('cart.coupon'), ['code' => 'diwali10']);
        $this->get('/cart/')->assertSee('is saved — add <b>₹550</b> more to get 10% off', false);
        $this->get(route('checkout'))->assertSee('needs orders of ₹999+', false);

        $this->addMug();   // 898 — still short
        $this->get('/cart/')->assertSee('add <b>₹101</b> more', false);
        $this->addMug();   // 1347 — applies
        $this->get('/cart/')->assertSee('Coupon <b>DIWALI10</b> applied', false)->assertSee('Discount (DIWALI10)');
    }

    public function test_coupon_that_stops_working_is_explained(): void
    {
        $c = Coupon::create(['code' => 'FLASH', 'type' => 'percent', 'value' => 10, 'is_active' => true]);
        $this->addMug();
        $this->post(route('cart.coupon'), ['code' => 'flash']);
        $c->update(['expires_at' => now()->subMinute()]);
        $this->get('/cart/')->assertSee('FLASH expired on')->assertSee('It has been removed.');
        $this->get('/cart/')->assertDontSee('FLASH expired on');
    }

    public function test_coupon_link_applies_on_the_cart(): void
    {
        Coupon::create(['code' => 'WA20', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $this->addMug();
        $this->get('/cart/?coupon=wa20')->assertSee('Discount (WA20)');
    }
}
