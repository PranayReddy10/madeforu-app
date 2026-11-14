<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrderNumberTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        $this->travelTo('2026-11-15 10:00:00');
    }

    private function make(string $number): Order
    {
        return Order::create(['number' => $number, 'name' => 'A', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'confirmed', 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'subtotal' => 1, 'total' => 1]);
    }

    public function test_numbers_go_up_from_1001_and_ignore_old_styles(): void
    {
        $this->make('MFU261114001');   // old date-style number
        $this->make('WC-22511');       // old WordPress order
        $this->assertSame('MFU20261001', Order::nextNumber());

        $this->make('MFU20261001');
        $this->assertSame('MFU20261002', Order::nextNumber());
        $this->make('MFU20261009');
        $this->assertSame('MFU20261010', Order::nextNumber());
    }

    public function test_counter_keeps_going_past_9999_and_restarts_each_year(): void
    {
        $this->make('MFU20269999');
        $this->assertSame('MFU202610000', Order::nextNumber());
        $this->make('MFU202610000');
        $this->assertSame('MFU202610001', Order::nextNumber());

        $this->travelTo('2027-01-01 00:05:00');
        $this->assertSame('MFU20271001', Order::nextNumber());
    }

    public function test_checkout_and_new_order_form_use_it(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $form = ['source' => 'whatsapp', 'name' => 'Asha', 'phone' => '9876543210', 'address_line1' => 'x', 'city' => 'Hyderabad',
            'state' => 'Telangana', 'pincode' => '500001', 'items' => [['product_id' => $mug->id, 'qty' => 1]], 'payment' => 'cod'];
        $this->actingAs($admin)->post('/admin/orders/new', $form);
        $this->actingAs($admin)->post('/admin/orders/new', $form);
        $this->assertSame(['MFU20261001', 'MFU20261002'], Order::orderBy('id')->pluck('number')->all());
    }
}
