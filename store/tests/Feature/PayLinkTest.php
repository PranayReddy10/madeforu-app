<?php

namespace Tests\Feature;

use App\Http\Controllers\PayLinkController;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderNotifier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class PayLinkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        Setting::put(['razorpay.enabled' => '1', 'razorpay.key' => 'rzp_test_k', 'razorpay.secret' => 'sekret', 'razorpay.webhook_secret' => 'whs']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        $this->useBrowserSession();
    }

    private function order(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU5501', 'name' => 'Asha Rao', 'email' => '', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'pending', 'payment_method' => 'razorpay',
            'payment_status' => 'unpaid', 'subtotal' => 449, 'discount' => 0, 'shipping' => 60, 'cod_fee' => 0, 'total' => 509,
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => 'Magic Photo Mug', 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    /** Open the link, press Pay (Razorpay order faked), then come back from Razorpay with a valid signature. */
    private function payThroughLink(Order $order, int $expectPaise): void
    {
        Http::fake(['api.razorpay.com/*' => Http::response(['id' => 'order_RZ1', 'amount' => $expectPaise, 'currency' => 'INR'])]);
        $url = PayLinkController::link($order);
        $this->get($url)->assertOk()->assertSee('Pay '.money($expectPaise / 100).' securely');
        $this->post($url)->assertOk()->assertSee('order_RZ1');
        Http::assertSent(fn ($r) => $r['amount'] === $expectPaise);
        $this->assertSame('order_RZ1', $order->fresh()->razorpay_order_id);

        $sig = hash_hmac('sha256', 'order_RZ1|pay_LINK1', 'sekret');
        $this->post(route('checkout.verify'), ['razorpay_order_id' => 'order_RZ1', 'razorpay_payment_id' => 'pay_LINK1', 'razorpay_signature' => $sig])
            ->assertRedirect(route('checkout.thanks', $order->number));
        $this->get(route('checkout.thanks', $order->number))->assertOk();
    }

    public function test_link_must_be_signed(): void
    {
        $order = $this->order();
        $this->get('/pay/'.$order->number)->assertForbidden();
        $this->get(PayLinkController::link($order).'x')->assertForbidden();
    }

    public function test_whatsapp_order_paid_through_the_link_is_confirmed(): void
    {
        $order = $this->order();
        $this->payThroughLink($order, 50900);
        $order->refresh();
        $this->assertSame(['paid', 'confirmed', 'pay_LINK1'], [$order->payment_status, $order->status, $order->razorpay_payment_id]);
        $this->assertNotNull($order->invoice_number);
        $this->get(PayLinkController::link($order))->assertSee('already paid');
    }

    public function test_cod_customer_paying_by_link_saves_the_cod_charge(): void
    {
        $order = $this->order(['status' => 'confirmed', 'payment_method' => 'cod', 'cod_fee' => 50, 'total' => 559]);
        $this->get(PayLinkController::link($order))->assertSee('You save the ₹50 cash on delivery charge');
        $this->payThroughLink($order, 50900);
        $order->refresh();
        $this->assertSame(['razorpay', 'paid', 'confirmed'], [$order->payment_method, $order->payment_status, $order->status]);
        $this->assertEquals([0, 509], [(float) $order->cod_fee, (float) $order->total]);
    }

    public function test_webhook_accepts_the_link_amount(): void
    {
        $order = $this->order(['status' => 'confirmed', 'payment_method' => 'cod', 'cod_fee' => 50, 'total' => 559, 'razorpay_order_id' => 'order_W1']);
        $body = json_encode(['event' => 'payment.captured', 'payload' => ['payment' => ['entity' => ['id' => 'pay_W1', 'order_id' => 'order_W1', 'amount' => 50900]]]]);
        $this->call('POST', '/webhooks/razorpay', [], [], [], ['HTTP_X_RAZORPAY_SIGNATURE' => hash_hmac('sha256', $body, 'whs'), 'CONTENT_TYPE' => 'application/json'], $body)->assertOk();
        $this->assertSame('paid', $order->fresh()->payment_status);
    }

    public function test_no_link_for_booked_cod_or_without_a_gateway(): void
    {
        $booked = $this->order(['status' => 'confirmed', 'payment_method' => 'cod', 'cod_fee' => 50, 'total' => 559, 'tracking_number' => 'AWB9', 'courier' => 'Delhivery']);
        $this->assertStringContainsString('Cancel that shipment first', $booked->payLinkProblem());
        $this->actingAs($this->admin)->get(route('admin.orders.show', $booked))->assertSee('Cancel that shipment first');

        Setting::put(['razorpay.enabled' => '0']);
        $this->assertStringContainsString('Switch on Razorpay or Cashfree', $this->order(['number' => 'MFU5502'])->payLinkProblem());
    }

    public function test_admin_page_and_messages_offer_the_link(): void
    {
        $order = $this->order();
        $link = PayLinkController::link($order);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Payment link')->assertSee($link)->assertSee('Send on WhatsApp');
        $msgs = collect(OrderNotifier::waMessages($order));
        $this->assertStringContainsString($link, $msgs->firstWhere('label', 'Payment link')['text']);
        $this->assertTrue($msgs->firstWhere('label', 'Payment link')['now']);
    }

    public function test_new_order_with_payment_link_waits_for_payment(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs($this->admin)->post('/admin/orders/new', [
            'source' => 'whatsapp', 'name' => 'Ravi', 'phone' => '9123456780', 'address_line1' => 'x', 'city' => 'Hyderabad',
            'state' => 'Telangana', 'pincode' => '500001', 'items' => [['product_id' => $mug->id, 'qty' => 1]], 'payment' => 'link',
        ])->assertSessionHas('status', fn ($m) => str_contains($m, 'Send the payment link below'));
        $o = Order::latest('id')->first();
        $this->assertSame(['pending', 'razorpay', 'unpaid'], [$o->status, $o->payment_method, $o->payment_status]);
        $this->assertNull($o->invoice_number);
        $this->assertNull($o->payLinkProblem());
    }
}
