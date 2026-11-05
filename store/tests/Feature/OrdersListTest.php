<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Models\User;
use App\Services\Delhivery;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class OrdersListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
        Setting::put(['delhivery.enabled' => '1', 'delhivery.token' => 'tok', 'delhivery.pickup_location' => ' Madeforu Hyd ', 'delhivery.mode' => 'production']);
    }

    private function order(array $over = []): Order
    {
        static $n = 0;
        $n++;

        return Order::create($over + [
            'number' => 'MFU-T'.$n, 'name' => 'Asha Rao', 'email' => 'a@example.com', 'phone' => '9876543210',
            'address_line1' => '12 MG Road', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001',
            'subtotal' => 300, 'shipping' => 0, 'total' => 300, 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'status' => 'confirmed',
        ]);
    }

    public function test_warehouse_error_is_explained_and_can_be_fixed_from_the_list(): void
    {
        $this->assertSame('Madeforu Hyd', Delhivery::pickupName());   // stray spaces trimmed
        $msg = Delhivery::explain('ClientWarehouse matching query does not exist.');
        $this->assertStringContainsString('“Madeforu Hyd” is not a warehouse in your Delhivery account', $msg);

        $o = $this->order(['shipment_error' => 'Delhivery did not accept the shipment: '.$msg]);
        $this->get('/admin/orders/')->assertOk()->assertSee('Delhivery couldn’t book', false)->assertSee('Register my pickup address with Delhivery')
            ->assertSee('Needs attention')->assertSee('Not booked');

        Http::fake(['track.delhivery.com/api/backend/clientwarehouse/create/' => Http::response(['success' => true, 'data' => ['name' => 'MADEFORU KORREMULA']])]);
        $this->post(route('admin.orders.warehouse'), ['name' => 'MADEFORU KORREMULA', 'phone' => '9381024794', 'address' => 'Plot 93, Laxmi Nagar Colony',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pin' => '500088'])->assertSessionHas('status');
        $this->assertSame('MADEFORU KORREMULA', setting('delhivery.pickup_location'));
        Http::assertSent(fn ($r) => str_contains($r->url(), 'clientwarehouse/create') && $r['pin'] === '500088' && $r['return_state'] === 'Telangana');
    }

    public function test_ticked_orders_are_booked_or_moved_together(): void
    {
        $a = $this->order(['shipment_error' => 'old error']);
        $b = $this->order();
        $pending = $this->order(['status' => 'pending']);
        Http::fake(['track.delhivery.com/api/cmu/create.json' => Http::sequence()
            ->push(['packages' => [['status' => 'Success', 'waybill' => 'AWB111']]])
            ->push(['packages' => [['status' => 'Success', 'waybill' => 'AWB222']]])]);

        $this->post(route('admin.orders.bulk'), ['action' => 'book', 'ids' => [$a->id, $b->id, $pending->id]])->assertSessionHas('status', '2 booked with Delhivery.');
        $this->assertSame(['AWB111', null], [$a->fresh()->tracking_number, $a->fresh()->shipment_error]);
        $this->assertSame('AWB222', $b->fresh()->tracking_number);
        $this->assertNull($pending->fresh()->tracking_number);

        $this->post(route('admin.orders.bulk'), ['action' => 'delivered', 'ids' => [$a->id, $b->id]])->assertSessionHas('status');
        $this->assertSame(['delivered', 'paid'], [$a->fresh()->status, $a->fresh()->payment_status]);   // COD collected
        $this->assertNotNull($b->fresh()->delivered_at);
    }

    public function test_shipment_data_is_cleaned_and_a_partly_saved_order_is_retried(): void
    {
        $o = $this->order(['name' => 'Priya 😊 R.', 'phone' => '+91 98480 12345', 'address_line1' => 'Flat #4 & 5; Sai Residency', 'address_line2' => 'Near "Temple" | Road 3']);
        OrderItem::create(['order_id' => $o->id, 'name' => 'Personalized Coffee Mug | Custom Photo Mug', 'qty' => 2, 'unit_price' => 150, 'line_total' => 300]);
        Http::fake(['track.delhivery.com/api/cmu/create.json' => Http::sequence()
            ->push(['packages' => [['status' => 'Fail', 'remarks' => ['Duplicate order id']]]])
            ->push(['packages' => [['status' => 'Success', 'waybill' => 'AWB9']]])]);

        $this->assertSame('AWB9', app(Delhivery::class)->createShipment($o));

        $sent = collect(Http::recorded())->map(fn ($p) => json_decode($p[0]['data'], true)['shipments'][0]);
        $this->assertSame([$o->number, $o->number.'-2'], $sent->pluck('order')->all());
        $s = $sent[0];
        $this->assertSame(['Priya R', '9848012345', 'Flat 4 and 5 Sai Residency, Near Temple Road 3', 'Personalized Coffee Mug x2'], [$s['name'], $s['phone'], $s['add'], $s['products_desc']]);

        $this->assertStringContainsString('fraud check', Delhivery::explain('Crashing while saving package due to exception suspicious order/consignee.'));
    }

    public function test_shipping_details_can_be_corrected_on_the_order(): void
    {
        $o = $this->order();
        $this->put(route('admin.orders.update', $o), ['status' => 'confirmed', 'payment_status' => 'unpaid', 'name' => 'Priya Sharma', 'phone' => '+91 98480 12345',
            'address_line1' => 'H.No 5-6, Kukatpally', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500072'])->assertSessionHasNoErrors();
        $o->refresh();
        $this->assertSame(['Priya Sharma', '9848012345', '500072'], [$o->name, $o->phone, $o->pincode]);
        $this->get(route('admin.orders.show', $o))->assertSee('Edit name, phone');
    }
}
