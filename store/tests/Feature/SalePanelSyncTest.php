<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class SalePanelSyncTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
        Setting::put(['sale.enabled' => '1', 'sale.url' => 'https://sale.example.test/', 'sale.key' => 'k123']);
    }

    private function order(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU8001', 'name' => 'Asha Rao', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'shipped', 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'subtotal' => 898, 'discount' => 50, 'coupon_code' => 'SAVE50', 'shipping' => 60, 'cod_fee' => 50,
            'extra_charge' => 40, 'extra_charge_label' => 'Gift wrap', 'total' => 998, 'tracking_number' => '1490777', 'courier' => 'Delhivery', 'shipped_at' => now()->subDay(),
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => 'Magic Photo Mug', 'qty' => 2, 'unit_price' => 449, 'unit_cost' => 160, 'line_total' => 898]);

        return $o;
    }

    public function test_marking_delivered_sends_the_order_with_exact_amounts(): void
    {
        Http::fake(['sale.example.test/*' => Http::response(['ok' => true, 'order_no' => 'ORD261105007', 'message' => 'Added'])]);
        $o = $this->order();
        $o->update(['status' => 'delivered', 'delivered_at' => now(), 'payment_status' => 'paid']);
        app()->terminate();   // after-response jobs run here

        Http::assertSent(function (Request $r) {
            $d = $r->data();

            return $r->url() === 'https://sale.example.test/api/website.php' && $r->hasHeader('X-Website-Key', 'k123') && $d['key'] === 'k123'
                && $d['action'] === 'upsert' && $d['web_order_no'] === 'MFU8001'
                && $d['items'][0] === ['name' => 'Magic Photo Mug', 'qty' => 2, 'unit_price' => 449.0, 'unit_cost' => 160.0]
                && $d['discount'] == 50 && $d['discount_reason'] === 'Coupon SAVE50'
                && $d['extra_charge'] == 150 && $d['extra_charge_reason'] === 'Shipping ₹60 + COD ₹50 + Gift wrap ₹40'
                && $d['payment'] === ['amount' => 998.0, 'mode' => 'cash', 'note' => 'COD via Delhivery, AWB 1490777']
                && $d['awb'] === '1490777';
        });
        $o->refresh();
        $this->assertSame('ORD261105007', $o->sale_order_no);
        $this->assertNotNull($o->sale_synced_at);
        // 898 − 50 + 150 = 998: the panel's own total comes out the same.
        $this->assertEquals(998, 898 - 50 + 150);
    }

    public function test_returned_after_delivery_is_queued_for_removal(): void
    {
        \Illuminate\Support\Facades\Bus::fake([\App\Jobs\SendToSalePanel::class]);
        $o = $this->order();
        $o->update(['status' => 'delivered']);
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\SendToSalePanel::class, fn ($j) => $j->orderId === $o->id && $j->action === 'upsert');
        $o->forceFill(['sale_synced_at' => now()])->saveQuietly();
        $o->refresh()->update(['status' => 'returned']);
        \Illuminate\Support\Facades\Bus::assertDispatchedAfterResponse(\App\Jobs\SendToSalePanel::class, fn ($j) => $j->orderId === $o->id && $j->action === 'remove');
    }

    public function test_remove_and_failures_are_recorded_on_the_order(): void
    {
        Http::fake(['sale.example.test/*' => Http::sequence()
            ->push(['ok' => true, 'removed' => true])
            ->push(['ok' => false, 'error' => ['message' => 'The website sync key is wrong.']], 401)]);
        $o = $this->order(['status' => 'returned', 'sale_order_no' => 'ORD1', 'sale_synced_at' => now()]);
        $this->assertTrue(\App\Services\SalePanel::remove($o));
        Http::assertSent(fn (Request $r) => $r->data()['action'] === 'remove' && $r->data()['web_order_no'] === 'MFU8001');
        $this->assertNull($o->fresh()->sale_synced_at);

        $o->refresh()->forceFill(['status' => 'delivered'])->saveQuietly();
        $this->assertFalse(\App\Services\SalePanel::push($o));
        $this->assertSame('The website sync key is wrong.', $o->fresh()->sale_sync_error);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $o))->assertSee('Not sent: The website sync key is wrong.');
    }

    public function test_nothing_is_sent_when_switched_off(): void
    {
        Http::fake();
        Setting::put(['sale.enabled' => '']);
        $this->order()->update(['status' => 'delivered']);
        app()->terminate();
        Http::assertNothingSent();
    }

    public function test_sales_page_test_and_send_older_orders(): void
    {
        Http::fake(['sale.example.test/*' => Http::response(['ok' => true, 'order_no' => 'ORD9', 'message' => 'Connected to the sale panel.'])]);
        Setting::put(['sale.enabled' => '']);   // older orders delivered before sync was on
        $this->order(['status' => 'delivered']);
        $this->order(['number' => 'MFU8002', 'status' => 'delivered', 'payment_method' => 'razorpay', 'payment_status' => 'paid', 'razorpay_payment_id' => 'pay_X1']);
        Setting::put(['sale.enabled' => '1']);

        $this->actingAs($this->admin)->get('/admin/sales')->assertOk()->assertSee('Send delivered orders now (2)');
        $this->actingAs($this->admin)->post('/admin/sales/panel-test')->assertSessionHas('status', '✓ Connected to the sale panel.');
        $this->actingAs($this->admin)->post('/admin/sales/panel-send')->assertSessionHas('status', '2 orders sent to the sale panel.');
        Http::assertSent(fn (Request $r) => ($r->data()['web_order_no'] ?? '') === 'MFU8002' && $r->data()['payment']['mode'] === 'other' && $r->data()['payment']['note'] === 'Razorpay pay_X1');
        $this->actingAs($this->admin)->get('/admin/sales')->assertDontSee('Send delivered orders now');
    }
}
