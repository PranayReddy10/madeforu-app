<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PaymentsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
        'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        Setting::put(['cashfree.app_id' => 'TEST123', 'cashfree.secret' => 'cfsecret', 'cashfree.mode' => 'sandbox',
            'razorpay.key' => 'rzp_test_x', 'razorpay.secret' => 'rzsecret', 'payments.default' => 'cashfree']);
    }

    private function fillCart(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
    }

    public function test_checkout_offers_both_gateways_default_first(): void
    {
        $this->fillCart();
        $html = $this->get('/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('value="cashfree" checked', $html);
        $this->assertStringContainsString('value="razorpay"', $html);
        $this->assertLessThan(strpos($html, 'value="razorpay"'), strpos($html, 'value="cashfree"'));

        Setting::put(['cashfree.enabled' => '0']);
        $html = $this->get('/checkout')->getContent();
        $this->assertStringNotContainsString('value="cashfree"', $html);
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cashfree'])->assertSessionHasErrors('payment_method');
    }

    public function test_cashfree_payment_is_confirmed_with_cashfree_not_the_browser(): void
    {
        Http::fake([
            'sandbox.cashfree.com/pg/orders' => Http::response(['payment_session_id' => 'session_abc', 'order_status' => 'ACTIVE']),
        ]);
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cashfree'])
            ->assertOk()->assertSee('session_abc')->assertSee('"sandbox"', false);

        $order = Order::firstOrFail();
        $this->assertStringStartsWith($order->number.'-', $order->cashfree_order_id);
        Http::assertSent(fn ($r) => $r->url() === 'https://sandbox.cashfree.com/pg/orders'
            && $r->header('x-client-id')[0] === 'TEST123' && $r->header('x-client-secret')[0] === 'cfsecret'
            && $r['order_amount'] == $order->total && $r['customer_details']['customer_phone'] === '9876543210');

        // Customer comes back but Cashfree says not paid yet
        Http::fake(['sandbox.cashfree.com/pg/orders/*' => Http::response(['order_status' => 'ACTIVE', 'order_amount' => (float) $order->total])]);
        $this->get(route('checkout.cashfree.return', ['order_id' => $order->cashfree_order_id]))->assertRedirect(route('checkout'));
        $this->assertSame('unpaid', $order->fresh()->payment_status);
    }

    public function test_cashfree_return_marks_paid_and_clears_cart(): void
    {
        Http::fake(['sandbox.cashfree.com/pg/orders' => Http::response(['payment_session_id' => 's1'])]);
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cashfree']);
        $order = Order::firstOrFail();

        Http::fake([
            'sandbox.cashfree.com/pg/orders/*/payments' => Http::response([['cf_payment_id' => 998877, 'payment_status' => 'SUCCESS']]),
            'sandbox.cashfree.com/pg/orders/*' => Http::response(['order_status' => 'PAID', 'order_amount' => (float) $order->total]),
        ]);
        $this->get(route('checkout.cashfree.return', ['order_id' => $order->cashfree_order_id]))->assertRedirect(route('checkout.thanks', $order->number));
        $order->refresh();
        $this->assertSame(['paid', 'confirmed', '998877'], [$order->payment_status, $order->status, $order->cashfree_payment_id]);
        $this->get('/cart')->assertSee('Your cart is empty');
        $this->get(route('checkout.thanks', $order->number))->assertOk();
    }

    public function test_cashfree_webhook_checks_signature_and_amount(): void
    {
        $order = Order::create(['number' => 'MFU9', 'status' => 'pending', 'payment_method' => 'cashfree', 'cashfree_order_id' => 'MFU9-abcd',
            'subtotal' => 500, 'total' => 500] + self::ADDRESS);
        $body = json_encode(['type' => 'PAYMENT_SUCCESS_WEBHOOK', 'data' => [
            'order' => ['order_id' => 'MFU9-abcd', 'order_amount' => 500],
            'payment' => ['cf_payment_id' => 5551, 'payment_status' => 'SUCCESS', 'payment_amount' => 500],
        ]]);
        $ts = (string) time();
        $send = fn ($sig) => $this->call('POST', route('webhooks.cashfree'), [], [], [], [
            'CONTENT_TYPE' => 'application/json', 'HTTP_X_WEBHOOK_TIMESTAMP' => $ts, 'HTTP_X_WEBHOOK_SIGNATURE' => $sig,
        ], $body);

        $send('forged')->assertStatus(400);
        $this->assertSame('unpaid', $order->fresh()->payment_status);

        $send(base64_encode(hash_hmac('sha256', $ts.$body, 'cfsecret', true)))->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('5551', $order->fresh()->cashfree_payment_id);
    }

    public function test_admin_saves_gateway_keys_encrypted_and_keeps_them_when_blank(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/settings')->assertOk()->assertSee('Cashfree Secret Key')->assertDontSee('cfsecret');

        $this->actingAs($admin)->put('/admin/settings', ['cashfree__secret' => 'newsecret', 'cashfree__app_id' => 'APP2', 'payments__default' => 'razorpay'])->assertRedirect();
        $raw = Setting::find('cashfree.secret')->value;
        $this->assertStringStartsWith('enc:', $raw);
        $this->assertSame('newsecret', setting('cashfree.secret'));

        $this->actingAs($admin)->put('/admin/settings', ['cashfree__secret' => '', 'cashfree__app_id' => 'APP2', 'payments__default' => 'razorpay']);
        $this->assertSame('newsecret', setting('cashfree.secret'));
        $this->assertSame('APP2', setting('cashfree.app_id'));
    }
}
