<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\OrderNotifier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WhatsappMessagesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function order(array $over): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU7001', 'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210',
            'address_line1' => '12 MG Road', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001',
            'subtotal' => 449, 'discount' => 0, 'shipping' => 60, 'cod_fee' => 0, 'total' => 509,
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    private function now(Order $o): array
    {
        return collect(OrderNotifier::waMessages($o))->where('now', true)->pluck('label')->all();
    }

    public function test_messages_fit_each_stage(): void
    {
        $failed = $this->order(['status' => 'pending', 'payment_method' => 'razorpay', 'payment_status' => 'failed']);
        $this->assertContains('Payment failed — pay again or COD', $this->now($failed));
        $text = collect(OrderNotifier::waMessages($failed))->firstWhere('label', 'Payment failed — pay again or COD')['text'];
        $this->assertStringContainsString('Hi Asha!', $text);
        $this->assertStringContainsString('*MFU7001*', $text);
        $this->assertStringContainsString('cash on delivery', $text);

        $cod = $this->order(['number' => 'MFU7002', 'status' => 'confirmed', 'payment_method' => 'cod', 'payment_status' => 'unpaid']);
        $this->assertContains('Confirm COD order', $this->now($cod));
        $this->assertContains('Confirm address', $this->now($cod));
        $this->assertNotContains('Payment failed — pay again or COD', $this->now($cod));

        $making = $this->order(['number' => 'MFU7003', 'status' => 'in_production', 'payment_method' => 'razorpay', 'payment_status' => 'paid']);
        $this->assertContains('Being made', $this->now($making));

        $shipped = $this->order(['number' => 'MFU7004', 'status' => 'shipped', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'tracking_number' => 'AWB1', 'courier' => 'Delhivery']);
        $this->assertContains('Shipped', $this->now($shipped));
        $this->assertNotContains('Confirm COD order', collect(OrderNotifier::waMessages($shipped))->pluck('label')->all());

        $delivered = $this->order(['number' => 'MFU7005', 'status' => 'delivered', 'payment_method' => 'cod', 'payment_status' => 'paid']);
        $this->assertSame('Delivered — ask for a review', $this->now($delivered)[0]);
        $this->assertStringContainsString('/review/MFU7005', collect(OrderNotifier::waMessages($delivered))->firstWhere('label', 'Delivered — ask for a review')['text']);

        $cancelled = $this->order(['number' => 'MFU7006', 'status' => 'cancelled', 'payment_method' => 'razorpay', 'payment_status' => 'paid']);
        $this->assertStringContainsString('refund', collect(OrderNotifier::waMessages($cancelled))->firstWhere('label', 'Order cancelled')['text']);
    }

    public function test_order_page_lists_them(): void
    {
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $o = $this->order(['status' => 'confirmed', 'payment_method' => 'cod', 'payment_status' => 'unpaid']);
        $this->actingAs($admin)->get(route('admin.orders.show', $o))->assertOk()
            ->assertSee('For this order now')->assertSee('Confirm COD order')->assertSee('More messages')
            ->assertSee('https://wa.me/919876543210?text=', false);
    }
}
