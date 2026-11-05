<?php

namespace Tests\Feature;

use App\Models\CustomerUpload;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeleteCancelledOrdersTest extends TestCase
{
    use RefreshDatabase;

    private function order(string $number, string $status): Order
    {
        $o = Order::create(['number' => $number, 'status' => $status, 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'subtotal' => 100, 'total' => 100, 'name' => 'Asha', 'email' => 'a@example.com', 'phone' => '9876543210',
            'address_line1' => 'x', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001']);
        $o->items()->create(['name' => 'Mug', 'qty' => 1, 'unit_price' => 100, 'line_total' => 100]);

        return $o;
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->forceFill(['is_admin' => true])->save();

        return $a;
    }

    public function test_cancelled_order_can_be_deleted_with_its_photos(): void
    {
        Storage::fake('local');
        $o = $this->order('MFU1', 'cancelled');
        Storage::disk('local')->put('uploads/x.jpg', 'img');
        CustomerUpload::create(['token' => str_repeat('a', 36), 'session_id' => 's', 'path' => 'uploads/x.jpg', 'original_name' => 'x.jpg',
            'mime' => 'image/jpeg', 'size' => 3, 'order_item_id' => $o->items->first()->id]);

        $this->actingAs($this->admin())->get(route('admin.orders.show', $o))->assertSee('Delete order');
        $this->delete(route('admin.orders.destroy', $o))->assertRedirect(route('admin.orders.index'));
        $this->assertDatabaseMissing('orders', ['id' => $o->id]);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertDatabaseCount('customer_uploads', 0);
        Storage::disk('local')->assertMissing('uploads/x.jpg');
    }

    public function test_only_cancelled_orders_are_deleted(): void
    {
        $live = $this->order('MFU2', 'confirmed');
        $gone = $this->order('MFU3', 'cancelled');
        $admin = $this->admin();

        $this->actingAs($admin)->get(route('admin.orders.show', $live))->assertDontSee('Delete order');
        $this->delete(route('admin.orders.destroy', $live))->assertSessionHas('error');
        $this->assertDatabaseHas('orders', ['id' => $live->id]);

        $this->post(route('admin.orders.bulk'), ['ids' => [$live->id, $gone->id], 'action' => 'delete'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '1 cancelled order deleted') && str_contains($m, '1 not cancelled'));
        $this->assertDatabaseHas('orders', ['id' => $live->id]);
        $this->assertDatabaseMissing('orders', ['id' => $gone->id]);
    }
}
