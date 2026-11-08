<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderChargesTest extends TestCase
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
    }

    /** A Razorpay order whose payment failed: ₹548 items, ₹30 pay-online discount, ₹60 shipping. */
    private function failedOrder(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU9001', 'name' => 'Asha', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'address_line1' => '12 MG Road', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001',
            'status' => 'pending', 'payment_method' => 'razorpay', 'payment_status' => 'failed',
            'subtotal' => 548, 'discount' => 30, 'prepaid_discount' => 30, 'shipping' => 60, 'cod_fee' => 0, 'total' => 578,
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 1, 'unit_price' => 548, 'line_total' => 548]);

        return $o;
    }

    public function test_failed_payment_switched_to_cod_with_extra_charge_is_confirmed(): void
    {
        $order = $this->failedOrder();
        $soldBefore = (int) Product::where('slug', 'magic-photo-mug')->value('sales_count');
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Payment failed.')->assertSee('Save &amp; confirm order', false);

        $this->actingAs($this->admin)->from(route('admin.orders.show', $order))->post(route('admin.orders.charges', $order), [
            'payment_method' => 'cod', 'shipping' => 60, 'cod_fee' => 50, 'extra_charge' => 40, 'extra_charge_label' => 'Rush making', 'notify' => 1,
        ])->assertRedirect()->assertSessionHas('status');

        $order->refresh();
        $this->assertSame('cod', $order->payment_method);
        $this->assertSame('unpaid', $order->payment_status);
        $this->assertSame('confirmed', $order->status);
        $this->assertEquals(0, (float) $order->discount);            // online-payment discount removed
        $this->assertEquals(548 + 60 + 50 + 40, (float) $order->total);
        $this->assertNotNull($order->invoice_number);
        $this->assertSame($soldBefore + 1, (int) Product::where('slug', 'magic-photo-mug')->value('sales_count'));
        Mail::assertSent(\App\Mail\OrderPlaced::class);

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('Rush making')->assertSee('₹698');
        $this->assertStringContainsString('Rush making', implode(' ', array_column(\App\Services\Invoice::breakdown($order)['charges'], 'name')));
    }

    public function test_coupon_discount_stays_and_coupon_counts_once_confirmed(): void
    {
        Coupon::create(['code' => 'WELCOME5', 'type' => 'fixed', 'value' => 20, 'is_active' => true]);
        $order = $this->failedOrder(['coupon_code' => 'WELCOME5', 'discount' => 50, 'prepaid_discount' => 30, 'total' => 558]);
        $this->actingAs($this->admin)->post(route('admin.orders.charges', $order), ['payment_method' => 'cod', 'shipping' => 60, 'cod_fee' => 0]);
        $order->refresh();
        $this->assertEquals(20, (float) $order->discount);
        $this->assertEquals(548 - 20 + 60, (float) $order->total);
        $this->assertSame(1, Coupon::where('code', 'WELCOME5')->value('used'));
    }

    public function test_charges_are_locked_once_paid_booked_or_shipped(): void
    {
        foreach ([['payment_status' => 'paid', 'status' => 'confirmed'], ['tracking_number' => 'AWB123', 'courier' => 'Delhivery'], ['status' => 'shipped']] as $i => $state) {
            $order = $this->failedOrder($state + ['number' => 'MFU91'.$i]);
            $before = (float) $order->total;
            $this->actingAs($this->admin)->post(route('admin.orders.charges', $order), ['payment_method' => 'cod', 'shipping' => 999])
                ->assertSessionHas('error');
            $this->assertEquals($before, (float) $order->fresh()->total);
        }
    }

    public function test_extra_charge_on_an_existing_cod_order(): void
    {
        $order = $this->failedOrder(['status' => 'confirmed', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'discount' => 0, 'prepaid_discount' => 0, 'cod_fee' => 50, 'total' => 658]);
        $this->actingAs($this->admin)->post(route('admin.orders.charges', $order), ['payment_method' => 'cod', 'shipping' => 0, 'cod_fee' => 50, 'extra_charge' => 25, 'extra_charge_label' => 'Gift wrap'])
            ->assertSessionHas('status');
        $order->refresh();
        $this->assertEquals(548 + 0 + 50 + 25, (float) $order->total);
        $this->assertSame('confirmed', $order->status);
    }

    public function test_cod_order_paid_directly_by_upi_is_marked_paid_without_cod_charge(): void
    {
        $order = $this->failedOrder(['status' => 'confirmed', 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'discount' => 0, 'prepaid_discount' => 0, 'cod_fee' => 50, 'total' => 658]);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('Paid to us — UPI / GPay')->assertDontSee('link)');

        $this->actingAs($this->admin)->post(route('admin.orders.charges', $order), ['payment_method' => 'upi', 'payment_ref' => 'UTR4321', 'shipping' => 60, 'cod_fee' => 50])
            ->assertSessionHas('status', 'Marked as paid by UPI — ₹608. No cash to collect on delivery.');
        $order->refresh();
        $this->assertSame(['upi', 'paid', 'confirmed'], [$order->payment_method, $order->payment_status, $order->status]);
        $this->assertEquals(0, (float) $order->cod_fee);
        $this->assertEquals(548 + 60, (float) $order->total);
        $this->assertStringContainsString('Paid directly by UPI, ref UTR4321', $order->notes);
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('UPI · paid')->assertSee('already paid');
    }

    public function test_failed_online_payment_then_paid_by_bank_transfer_is_confirmed(): void
    {
        $order = $this->failedOrder();
        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertSee('Pay online (Razorpay link)');
        $this->actingAs($this->admin)->post(route('admin.orders.charges', $order), ['payment_method' => 'bank', 'shipping' => 60, 'notify' => 1]);
        $order->refresh();
        $this->assertSame(['bank', 'paid', 'confirmed'], [$order->payment_method, $order->payment_status, $order->status]);
        $this->assertEquals(578, (float) $order->total);   // pay-online discount kept: it was paid to us
        $this->assertNotNull($order->invoice_number);
    }
}

