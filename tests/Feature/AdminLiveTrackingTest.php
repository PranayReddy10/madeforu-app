<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class AdminLiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        Setting::put(['delhivery.enabled' => '1', 'delhivery.token' => 'tok', 'delhivery.mode' => 'production']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function order(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU8801', 'name' => 'Asha', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'shipped', 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'subtotal' => 449, 'discount' => 0, 'shipping' => 60, 'cod_fee' => 0, 'total' => 509,
            'tracking_number' => '1490888', 'courier' => 'Delhivery', 'shipment_status' => 'In Transit', 'shipped_at' => now()->subDay(),
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => 'Magic Photo Mug', 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    private function fake(string $status, string $type, string $where, string $note = ''): void
    {
        Http::fake(['*/api/v1/packages/json/*' => Http::response(['ShipmentData' => [['Shipment' => [
            'Status' => ['Status' => $status, 'StatusType' => $type, 'StatusDateTime' => now()->toIso8601String(), 'StatusLocation' => $where],
            'ExpectedDeliveryDate' => now()->addDay()->format('Y-m-d\TH:i:s'),
            'Scans' => [['ScanDetail' => ['Scan' => $status, 'ScannedLocation' => $where, 'ScanDateTime' => now()->toIso8601String(), 'Instructions' => $note]]],
        ]]]])]);
    }

    public function test_opening_the_order_checks_delhivery_and_shows_where_it_is(): void
    {
        $o = $this->order();
        $this->fake('Dispatched', 'UD', 'Hyderabad_Kukatpally (Telangana)', 'Out for delivery');
        $this->actingAs($this->admin)->get(route('admin.orders.show', $o))->assertOk()
            ->assertSee('Out for delivery today')->assertSee('Hyderabad, Kukatpally (Telangana)')->assertSee('Expected delivery');
        Http::assertSentCount(1);
    }

    public function test_delivered_parcel_updates_the_order_before_the_page_shows(): void
    {
        $o = $this->order();
        $this->fake('Delivered', 'DL', 'Hyderabad_Kukatpally (Telangana)', 'Delivered to consignee');
        $this->actingAs($this->admin)->get(route('admin.orders.show', $o))->assertOk()
            ->assertSee('✓ Delivered')->assertSee('Cash collected by Delhivery')->assertSee('Delivered</span>', false);
        $this->assertSame(['delivered', 'paid'], [$o->fresh()->status, $o->fresh()->payment_status]);
    }

    public function test_orders_list_checks_stale_parcels_after_the_page(): void
    {
        $stale = $this->order(['tracked_at' => now()->subHours(2)]);
        $this->order(['number' => 'MFU8802', 'tracking_number' => '1490999', 'tracked_at' => now()->subMinutes(5)]);   // fresh: left alone
        $this->order(['number' => 'MFU8803', 'tracking_number' => '1490777', 'status' => 'delivered']);                  // done: left alone
        $this->fake('Delivered', 'DL', 'Hyderabad_Kukatpally (Telangana)');

        $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertOk();
        app()->terminate();
        Http::assertSentCount(1);
        $this->assertSame('delivered', $stale->fresh()->status);
        $this->actingAs($this->admin)->get(route('admin.orders.index'))->assertSee('✓ Delivered');
    }
}
