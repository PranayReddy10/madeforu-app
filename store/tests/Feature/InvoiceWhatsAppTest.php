<?php

namespace Tests\Feature;

use App\Mail\OrderPlaced;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Invoice;
use App\Services\OrderNotifier;
use App\Services\Shipping;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InvoiceWhatsAppTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
        'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        Setting::put(['invoice.gstin' => '36ABCDE1234F1Z5', 'invoice.state' => 'Telangana', 'invoice.gst_rate' => '18', 'invoice.prefix' => 'MFU']);
    }

    private function order(array $over = []): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + ['number' => 'MFU'.random_int(100000, 999999), 'status' => 'confirmed', 'payment_method' => 'cod',
            'subtotal' => 1180, 'shipping' => 0, 'cod_fee' => 0, 'total' => 1180] + self::ADDRESS);
        $o->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 2, 'unit_price' => 590, 'line_total' => 1180]);

        return $o;
    }

    public function test_invoice_numbers_run_per_financial_year(): void
    {
        Carbon::setTestNow('2027-03-31 10:00');
        $this->assertSame('MFU/26-27/0001', Invoice::assign($this->order()));
        $this->assertSame('MFU/26-27/0002', Invoice::assign($this->order()));
        $o = $this->order();
        Invoice::assign($o);
        $this->assertSame('MFU/26-27/0003', Invoice::assign($o));   // assigning twice keeps the number
        Carbon::setTestNow('2027-04-01 09:00');
        $this->assertSame('MFU/27-28/0001', Invoice::assign($this->order()));
        Carbon::setTestNow();
    }

    public function test_gst_split_by_state_and_plain_invoice_without_gstin(): void
    {
        $b = Invoice::breakdown($this->order());                          // Telangana -> Telangana
        $this->assertTrue($b['intra']);
        $this->assertEquals(1000, $b['taxable']);                          // 1180 incl. 18%
        $this->assertEquals(180, $b['tax']);

        $b = Invoice::breakdown($this->order(['state' => 'Karnataka']));
        $this->assertFalse($b['intra']);

        Setting::put(['invoice.gstin' => '']);
        $b = Invoice::breakdown($this->order());
        $this->assertFalse($b['gst']);
        $this->assertEquals(0, $b['tax']);
        $this->assertSame('Rupees One Thousand One Hundred Eighty Only', Invoice::inWords(1180));
        $this->assertSame('Rupees Twelve Lakh Thirty Four Thousand Five Hundred Sixty Seven and Fifty Paise Only', Invoice::inWords(1234567.5));
    }

    public function test_cod_order_gets_invoice_attached_and_customer_can_download(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod']);

        $order = Order::firstOrFail();
        $this->assertStringStartsWith('MFU/', $order->invoice_number);
        Mail::assertSent(OrderPlaced::class, fn ($m) => $m->order->is($order) && count($m->attachments()) === 1);

        $this->get(route('checkout.thanks', $order->number))->assertSee('Download invoice');
        $pdf = $this->get(route('invoice.download', $order->number))->assertOk();
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertStringStartsWith('%PDF', $pdf->getContent());
    }

    public function test_invoice_is_private_without_a_signed_link(): void
    {
        $order = $this->order();
        Invoice::assign($order);
        $this->withCookie(config('session.cookie'), str_repeat('x', 40));
        $this->get(route('invoice.download', $order->number))->assertForbidden();
        $this->get(Invoice::publicUrl($order))->assertOk();
    }

    public function test_whatsapp_messages_for_each_step(): void
    {
        Setting::put(['whatsapp_api.enabled' => '1', 'whatsapp_api.phone_number_id' => '1122', 'whatsapp_api.token' => 'EAAG',
            'whatsapp_api.admin_numbers' => '919000000001', 'delhivery.enabled' => '1', 'delhivery.token' => 't']);
        Http::fake([
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.X']]]),
            'track.delhivery.com/api/v1/packages/*' => Http::response(['ShipmentData' => [['Shipment' => ['Status' => ['Status' => 'In Transit', 'StatusType' => 'UD'], 'Scans' => []]]]]),
        ]);
        $order = $this->order(['tracking_number' => 'AWB9', 'courier' => 'Delhivery']);
        Invoice::assign($order);

        app(OrderNotifier::class)->confirmed($order->load('items'));
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/1122/messages') && $r['to'] === '919876543210'
            && $r['template']['name'] === 'order_confirmed'
            && $r['template']['components'][0]['parameters'][0]['type'] === 'document'
            && $r['template']['components'][1]['parameters'][1]['text'] === $order->number);
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['to'] === '919000000001' && $r['template']['name'] === 'new_order_alert');

        // Sent once only, even if triggered again
        app(OrderNotifier::class)->confirmed($order);
        $this->assertSame(1, NotificationLog::where('event', 'confirmed')->count());

        app(Shipping::class)->sync($order);
        $this->assertTrue(NotificationLog::where('event', 'shipped')->where('status', 'sent')->exists());
        Http::assertSent(fn ($r) => str_ends_with($r->url(), '/messages') && $r['template']['name'] === 'order_shipped'
            && $r['template']['components'][0]['parameters'][3]['text'] === 'AWB9');
    }

    public function test_whatsapp_failure_is_logged_not_thrown(): void
    {
        Setting::put(['whatsapp_api.enabled' => '1', 'whatsapp_api.phone_number_id' => '1122', 'whatsapp_api.token' => 'EAAG', 'whatsapp_api.admin_numbers' => '']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'Template name does not exist in the translation']], 404)]);
        $order = $this->order();
        app(OrderNotifier::class)->confirmed($order->load('items'));
        $log = NotificationLog::firstOrFail();
        $this->assertSame('failed', $log->status);
        $this->assertStringContainsString('Template name does not exist', $log->error);
    }

    public function test_admin_order_page_has_invoice_and_whatsapp_buttons(): void
    {
        $order = $this->order(['tracking_number' => 'AWB5', 'courier' => 'Delhivery']);
        Invoice::assign($order);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()
            ->assertSee('Open invoice PDF')->assertSee('https://wa.me/919876543210?text=', false)->assertSee('Shipped');
        $this->actingAs($admin)->get(route('admin.orders.invoice', $order))->assertOk()->assertHeader('Content-Type', 'application/pdf');
    }
}
