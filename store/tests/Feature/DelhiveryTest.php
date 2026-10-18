<?php

namespace Tests\Feature;

use App\Mail\OrderShipped;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Cart;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DelhiveryTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
        'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'];

    private const PIN_OK = ['delivery_codes' => [['postal_code' => ['pin' => 500001, 'district' => 'Hyderabad', 'pre_paid' => 'Y', 'cod' => 'Y', 'cash' => 'Y', 'state_code' => 'TG']]]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        Setting::put(['delhivery.enabled' => '1', 'delhivery.auto' => '1', 'delhivery.token' => 'dl-token', 'delhivery.pickup_location' => 'MADEFORU HYD',
            'delhivery.mode' => 'production', 'cod_fee' => '40', 'cod_fee_percent' => '0']);
    }

    private function fillCart(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
    }

    public function test_cod_order_books_delhivery_shipment_automatically(): void
    {
        Http::fake([
            'track.delhivery.com/c/api/pin-codes/*' => Http::response(self::PIN_OK),
            'track.delhivery.com/api/cmu/create.json' => Http::response(['success' => true, 'packages' => [['status' => 'Success', 'waybill' => '1490123456789', 'remarks' => []]]]),
        ]);
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertRedirect();

        $order = Order::firstOrFail();
        $this->assertSame('1490123456789', $order->tracking_number);
        $this->assertSame('Delhivery', $order->courier);
        $this->assertSame('Manifested', $order->shipment_status);
        Http::assertSent(function ($r) use ($order) {
            if (! str_contains($r->url(), '/api/cmu/create.json')) {
                return false;
            }
            parse_str($r->body(), $form);
            $data = json_decode($form['data'], true);
            $s = $data['shipments'][0];

            return $form['format'] === 'json' && $r->header('Authorization')[0] === 'Token dl-token'
                && $data['pickup_location']['name'] === 'MADEFORU HYD'
                && $s['payment_mode'] === 'COD' && (float) $s['cod_amount'] === (float) $order->total
                && $s['order'] === $order->number && $s['pin'] === '500001' && (int) $s['weight'] > 0;
        });
    }

    public function test_failed_booking_is_kept_for_admin_to_retry(): void
    {
        Http::fake([
            'track.delhivery.com/c/api/pin-codes/*' => Http::response(self::PIN_OK),
            'track.delhivery.com/api/cmu/create.json' => Http::sequence()
                ->push(['success' => false, 'packages' => [['status' => 'Fail', 'remarks' => ['ClientWarehouse matching query does not exist.']]]])
                ->push(['packages' => [['status' => 'Success', 'waybill' => 'AWB2']]]),
        ]);
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod']);
        $order = Order::firstOrFail();
        $this->assertNull($order->tracking_number);
        $this->assertStringContainsString('ClientWarehouse', $order->shipment_error);

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->post(route('admin.orders.ship', $order), ['weight_grams' => 450])->assertSessionHas('status');
        $this->assertSame('AWB2', $order->fresh()->tracking_number);
        $this->assertNull($order->fresh()->shipment_error);
        $this->assertSame(450, $order->fresh()->weight_grams);
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertSee('AWB2')->assertSee('Print label');
    }

    public function test_admin_sees_retry_after_a_failed_booking(): void
    {
        $order = Order::create(['number' => 'MFU8', 'status' => 'confirmed', 'payment_method' => 'cod', 'shipment_error' => 'Delhivery did not accept the shipment: bad pincode',
            'subtotal' => 500, 'total' => 540] + self::ADDRESS);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertSee('bad pincode')->assertSee('Retry Delhivery shipment');
    }

    public function test_pincodes_delhivery_cannot_serve_are_refused(): void
    {
        Http::fake(['track.delhivery.com/c/api/pin-codes/*' => Http::sequence()
            ->push(['delivery_codes' => []])                                          // 500001: not serviceable
            ->push(['delivery_codes' => [['postal_code' => ['pre_paid' => 'Y', 'cod' => 'N', 'cash' => 'N']]]])]); // 500002: prepaid only
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertSessionHasErrors('pincode');
        $this->post(route('checkout.place'), ['pincode' => '500002'] + self::ADDRESS + ['payment_method' => 'cod'])->assertSessionHasErrors('payment_method');
        $this->getJson(route('delivery.check', ['pin' => '500002']))->assertOk()->assertJson(['ok' => true, 'cod' => false]);
        $this->assertSame(0, Order::count());
    }

    public function test_tracking_sync_moves_orders_and_emails_customer(): void
    {
        $order = Order::create(['number' => 'MFU7', 'status' => 'confirmed', 'payment_method' => 'cod', 'payment_status' => 'unpaid',
            'tracking_number' => 'AWB7', 'courier' => 'Delhivery', 'subtotal' => 500, 'total' => 540, 'cod_fee' => 40] + self::ADDRESS);
        $scan = fn ($status, $type) => ['ShipmentData' => [['Shipment' => ['Status' => ['Status' => $status, 'StatusType' => $type, 'StatusDateTime' => '2026-10-19T10:00:00'],
            'Scans' => [['ScanDetail' => ['Scan' => $status, 'ScanDateTime' => '2026-10-19T10:00:00', 'ScannedLocation' => 'Hyderabad_Hub', 'Instructions' => '']]]]]]];
        Http::fake(['track.delhivery.com/api/v1/packages/*' => Http::sequence()->push($scan('In Transit', 'UD'))->push($scan('Delivered', 'DL'))]);

        $this->artisan('store:delhivery-sync')->assertSuccessful();
        $order->refresh();
        $this->assertSame(['shipped', 'In Transit'], [$order->status, $order->shipment_status]);
        Mail::assertSent(OrderShipped::class, 1);

        $this->artisan('store:delhivery-sync')->assertSuccessful();
        $order->refresh();
        $this->assertSame(['delivered', 'paid'], [$order->status, $order->payment_status]);   // COD collected
        Mail::assertSent(OrderShipped::class, 1);                                              // no second "shipped" email
        $this->get(route('track', ['number' => 'MFU7', 'phone' => '9876543210']))->assertSee('Hyderabad_Hub')->assertSee('delhivery.com/track/package/AWB7', false);
    }

    public function test_cod_charges_fixed_or_percent_whichever_is_higher(): void
    {
        $this->assertEquals(40, Cart::codCharge(1000));
        Setting::put(['cod_fee_percent' => '2']);
        $this->assertEquals(40, Cart::codCharge(1000));    // 2% = 20 < 40
        $this->assertEquals(50, Cart::codCharge(2500));    // 2% = 50 > 40
        Setting::put(['cod_fee' => '0']);
        $this->assertEquals(10, Cart::codCharge(500));
    }

    public function test_cod_charge_shows_as_its_own_line(): void
    {
        Http::fake(['*' => Http::response(self::PIN_OK)]);
        $this->fillCart();
        $html = $this->get('/checkout')->assertOk()->getContent();
        $this->assertStringContainsString('Cash on delivery charges', $html);
        $this->assertStringContainsString('data-totals="cod"', $html);
    }
}
