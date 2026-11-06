<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ManualOrderTest extends TestCase
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
        Setting::put(['free_shipping_over' => '999', 'shipping_fee' => '60', 'cod_fee' => '50', 'cod_fee_percent' => '']);
    }

    private function base(array $over = []): array
    {
        return $over + [
            'source' => 'whatsapp', 'name' => 'Asha Rao', 'phone' => '+91 98765 43210', 'email' => '',
            'address_line1' => '12 MG Road', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001',
        ];
    }

    public function test_page_lists_products_and_options(): void
    {
        $this->actingAs($this->admin)->get('/admin/orders')->assertSee('New order');
        $this->actingAs($this->admin)->get('/admin/orders/new')->assertOk()
            ->assertSee('Magic Photo Mug')->assertSee('Paid — UPI / GPay / PhonePe')->assertSee('nothing to upload');
    }

    public function test_prepaid_whatsapp_order_is_confirmed_paid_without_photos(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->with('customizationFields')->first();
        $box = $mug->customizationFields->firstWhere('type', 'select');
        $res = $this->actingAs($this->admin)->post('/admin/orders/new', $this->base([
            'items' => [['product_id' => $mug->id, 'qty' => 2, 'custom' => [$box->id => 'Premium gift box']]],
            'payment' => 'upi', 'payment_ref' => 'UTR998877', 'notes' => 'Use the 2nd photo', 'notify' => 1,
        ]));
        $order = Order::with('items')->latest('id')->firstOrFail();
        $res->assertRedirect(route('admin.orders.show', $order));

        // 449 + 99 gift box = 548 each, ×2 = 1096 → over 999 so free shipping.
        $this->assertEquals(1096, (float) $order->subtotal);
        $this->assertEquals(0, (float) $order->shipping);
        $this->assertEquals(1096, (float) $order->total);
        $this->assertSame(['confirmed', 'upi', 'paid'], [$order->status, $order->payment_method, $order->payment_status]);
        $this->assertSame('9876543210', $order->phone);
        $this->assertSame('', $order->email);
        $this->assertNotNull($order->invoice_number);
        $this->assertStringContainsString('UTR998877', $order->notes);
        $this->assertSame('whatsapp', $order->utm['utm_source']);
        $parts = collect($order->items[0]->customization)->pluck('value', 'label');
        $this->assertSame('Premium gift box', $parts[$box->label]);
        $this->assertStringContainsString('Photos on WhatsApp chat', $parts->implode(' '));
        $this->assertSame(0, $order->items[0]->uploads()->count());

        $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('UPI · paid')->assertSee('Photos on WhatsApp chat');
    }

    public function test_cod_order_with_agreed_price_discount_and_extra_charge(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs($this->admin)->post('/admin/orders/new', $this->base([
            'source' => 'instagram',
            'items' => [['product_id' => $mug->id, 'qty' => 1, 'unit_price' => 400]],
            'payment' => 'cod', 'discount' => 20, 'extra_charge' => 30, 'extra_charge_label' => 'Rush',
        ]))->assertSessionHasNoErrors();
        $o = Order::latest('id')->firstOrFail();
        // 400 − 20 = 380 (< 999) → shipping 60, COD 50, extra 30 = 520
        $this->assertSame(['cod', 'unpaid', 'confirmed'], [$o->payment_method, $o->payment_status, $o->status]);
        $this->assertEquals([400, 20, 60, 50, 30, 520], [(float) $o->subtotal, (float) $o->discount, (float) $o->shipping, (float) $o->cod_fee, (float) $o->extra_charge, (float) $o->total]);
    }

    public function test_variable_product_needs_its_option_and_phone_is_checked(): void
    {
        $frame = Product::where('slug', 'personalised-wooden-photo-frame')->first();
        $this->actingAs($this->admin)->post('/admin/orders/new', $this->base(['items' => [['product_id' => $frame->id, 'qty' => 1]], 'payment' => 'upi']))
            ->assertSessionHasErrors('items.0.variant_id');
        $this->actingAs($this->admin)->post('/admin/orders/new', $this->base(['phone' => '12345', 'items' => [['product_id' => $frame->id, 'qty' => 1]], 'payment' => 'upi']))
            ->assertSessionHasErrors('phone');
        $this->assertSame(0, Order::count());
    }

    public function test_upi_orders_have_no_gateway_fee_in_profit(): void
    {
        Setting::put(['profit.gateway_percent' => '2']);
        $o = new Order(['payment_method' => 'upi', 'total' => 1000]);
        $this->assertFalse($o->viaGateway());
        $this->assertSame('UPI', $o->paymentLabel());
        $this->assertTrue((new Order(['payment_method' => 'razorpay']))->viaGateway());
    }
}
