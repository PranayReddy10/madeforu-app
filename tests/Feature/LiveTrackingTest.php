<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class LiveTrackingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        Setting::put(['delhivery.enabled' => '1', 'delhivery.token' => 'tok', 'delhivery.mode' => 'production']);
    }

    private function order(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => 'MFU6601', 'name' => 'Asha', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'confirmed', 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'subtotal' => 449, 'discount' => 0, 'shipping' => 60, 'cod_fee' => 0, 'total' => 509,
            'tracking_number' => '1490111', 'courier' => 'Delhivery', 'shipment_status' => 'Manifested',
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => 'Magic Photo Mug', 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    private function fakeDelhivery(string $status, string $type, array $scans): void
    {
        Http::fake(['*/api/v1/packages/json/*' => Http::response(['ShipmentData' => [['Shipment' => [
            'Status' => ['Status' => $status, 'StatusType' => $type, 'StatusDateTime' => now()->toIso8601String(), 'StatusLocation' => end($scans)[1]],
            'ExpectedDeliveryDate' => now()->addDays(2)->format('Y-m-d\TH:i:s'),
            'Scans' => array_map(fn ($s) => ['ScanDetail' => ['Scan' => $s[0], 'ScannedLocation' => $s[1], 'ScanDateTime' => $s[2], 'Instructions' => $s[3] ?? '']], $scans),
        ]]]])]);
    }

    public function test_track_page_shows_live_status_place_eta_and_scans(): void
    {
        $this->order();
        $this->fakeDelhivery('In Transit', 'UD', [
            ['Manifested', 'Hyderabad_Uppal (Telangana)', now()->subDays(2)->toIso8601String()],
            ['In Transit', 'Hyderabad_Hub (Telangana)', now()->subDay()->toIso8601String(), 'Shipment picked up'],
            ['In Transit', 'Bengaluru_Hub (Karnataka)', now()->subHours(3)->toIso8601String(), 'Bag received at facility'],
        ]);

        $this->get('/track-order?number=mfu6601&phone=9876543210')->assertOk()
            ->assertSee('On the way')->assertSee('Bengaluru, Hub (Karnataka)')->assertSee('Expected by')
            ->assertSee(now()->addDays(2)->format('D, j M'))->assertSee('1490111')->assertSee('Bag received at facility');

        // The visit also moved the order along, and the next visit within 10 minutes doesn't ask Delhivery again.
        $this->assertSame('shipped', Order::where('number', 'MFU6601')->value('status'));
        $this->get('/track-order?number=MFU6601&phone=9876543210')->assertOk();
        Http::assertSentCount(1);
    }

    public function test_out_for_delivery(): void
    {
        $this->order(['status' => 'shipped']);
        $this->fakeDelhivery('Dispatched', 'UD', [['Out for delivery', 'Hyderabad_Kukatpally (Telangana)', now()->toIso8601String()]]);
        $this->get('/track-order?number=MFU6601&phone=9876543210')->assertSee('Out for delivery today');
    }

    public function test_delivered_moves_the_order_along(): void
    {
        $o = $this->order(['number' => 'MFU6602', 'tracking_number' => '1490222', 'status' => 'shipped']);
        $this->fakeDelhivery('Delivered', 'DL', [['Delivered', 'Hyderabad_Kukatpally (Telangana)', now()->toIso8601String()]]);
        $this->get('/track-order?number=MFU6602&phone=9876543210')->assertSee('Delivered');
        $this->assertSame(['delivered', 'paid'], [$o->fresh()->status, $o->fresh()->payment_status]);
    }

    public function test_courier_down_falls_back_to_stored_updates(): void
    {
        $this->order(['status' => 'shipped', 'shipment_status' => 'In Transit', 'shipment_scans' => [
            ['status' => 'In Transit', 'location' => 'Hyderabad_Hub (Telangana)', 'at' => now()->subDay()->toIso8601String(), 'note' => ''],
        ]]);
        Http::fake(['*' => Http::response('down', 500)]);
        $this->get('/track-order?number=MFU6601&phone=9876543210')->assertOk()->assertSee('On the way')->assertSee('Hyderabad, Hub (Telangana)');
    }

    public function test_not_yet_shipped_says_so(): void
    {
        $this->order(['tracking_number' => null, 'courier' => null, 'shipment_status' => null, 'status' => 'in_production']);
        Http::fake();
        $this->get('/track-order?number=MFU6601&phone=9876543210')->assertSee('Being made')->assertSee('share the tracking number as soon as');
        Http::assertNothingSent();
    }
}
