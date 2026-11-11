<?php

namespace Tests\Feature;

use App\Mail\AdminAlert;
use App\Mail\DailySummary;
use App\Mail\OrderPlaced;
use App\Mail\OrderShipped;
use App\Mail\OrderUpdate;
use App\Models\NotificationLog;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Services\Shipping;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class EmailNotificationsTest extends TestCase
{
    use RefreshDatabase;

    private const ADDRESS = ['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
        'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'];

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        Setting::put(['notify.admin_emails' => 'orders@shop.test, owner@shop.test']);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function order(array $over = []): Order
    {
        $o = Order::create($over + ['number' => 'MFU'.random_int(1000, 9999), 'status' => 'confirmed', 'payment_method' => 'cod', 'subtotal' => 449, 'total' => 449] + self::ADDRESS);
        $o->items()->create(['product_id' => Product::first()->id, 'name' => 'Magic Photo Mug', 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    private function setStatus(Order $o, string $status, string $payment = 'unpaid'): void
    {
        $this->actingAs($this->admin)->put(route('admin.orders.update', $o), ['status' => $status, 'payment_status' => $payment])->assertSessionHasNoErrors();
    }

    public function test_new_order_emails_customer_and_every_shop_address(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod']);

        Mail::assertSent(OrderPlaced::class, fn ($m) => $m->hasTo('asha@example.com') && ! $m->forAdmin);
        Mail::assertSent(OrderPlaced::class, fn ($m) => $m->hasTo('orders@shop.test') && $m->forAdmin);
        Mail::assertSent(OrderPlaced::class, fn ($m) => $m->hasTo('owner@shop.test') && $m->forAdmin);
        $this->assertSame(3, NotificationLog::where('channel', 'email')->where('status', 'sent')->count());
    }

    public function test_each_status_change_emails_the_customer_once(): void
    {
        $o = $this->order();
        $this->setStatus($o, 'in_production');
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'in_production' && $m->hasTo('asha@example.com'));
        $this->setStatus($o, 'confirmed');
        $this->setStatus($o, 'in_production');
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'in_production', 1);   // not twice

        $this->setStatus($o, 'shipped');
        Mail::assertSent(OrderShipped::class, 1);
        $this->setStatus($o, 'delivered', 'paid');
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'delivered');

        $c = $this->order();
        $this->setStatus($c, 'cancelled', 'refunded');
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'cancelled' && $m->order->is($c));
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'refunded' && $m->order->is($c));

        $this->actingAs($this->admin)->get(route('admin.orders.show', $o))->assertSee('Messages sent')->assertSee('in production');
    }

    public function test_switched_off_emails_and_imported_orders_send_nothing(): void
    {
        Setting::put(['notify.customer_in_production' => '0']);
        $this->setStatus($this->order(), 'in_production');
        Mail::assertNotSent(OrderUpdate::class);

        $old = $this->order(['woo_id' => 123, 'number' => 'WC-1']);
        $this->setStatus($old, 'cancelled');
        Mail::assertNotSent(OrderUpdate::class);
    }

    public function test_courier_updates_email_customer_and_shop(): void
    {
        Setting::put(['delhivery.enabled' => '1', 'delhivery.token' => 't']);
        $o = $this->order(['tracking_number' => 'AWB1', 'courier' => 'Delhivery', 'status' => 'shipped']);
        Http::fake(['track.delhivery.com/api/v1/packages/*' => Http::response(['ShipmentData' => [['Shipment' => ['Status' => ['Status' => 'RTO', 'StatusType' => 'RT'], 'Scans' => []]]]])]);
        app(Shipping::class)->sync($o);
        Mail::assertSent(OrderUpdate::class, fn ($m) => $m->event === 'returned');
        Mail::assertSent(AdminAlert::class, fn ($m) => str_contains($m->title, 'Parcel returned') && $m->hasTo('owner@shop.test'));

        $f = $this->order();
        Http::fake(['track.delhivery.com/api/cmu/create.json' => Http::response(['packages' => [['status' => 'Fail', 'remarks' => ['Pincode not serviceable']]]])]);
        app(Shipping::class)->ship($f);
        Mail::assertSent(AdminAlert::class, fn ($m) => str_contains($m->title, 'could not book') && collect($m->lines)->contains(fn ($l) => str_contains($l, 'Pincode not serviceable')));
    }

    public function test_pending_review_alerts_the_shop(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->post(route('product.review', $mug), ['name' => 'Ravi', 'rating' => 4, 'body' => 'Nice']);
        Mail::assertSent(AdminAlert::class, fn ($m) => str_contains($m->title, '4★ review waiting'));
        Review::create(['product_id' => $mug->id, 'name' => 'X', 'rating' => 5, 'is_approved' => true]);
        Mail::assertSent(AdminAlert::class, 1);
    }

    public function test_daily_summary(): void
    {
        $this->order();
        $this->order(['status' => 'pending', 'payment_method' => 'razorpay']);
        $this->artisan('store:daily-summary')->assertSuccessful();
        Mail::assertSent(DailySummary::class, fn ($m) => $m->s['orders_today'] === 1 && $m->s['to_make']->count() === 1
            && $m->s['unpaid_online']->count() === 1 && $m->hasTo('orders@shop.test'));
    }

    public function test_every_email_renders(): void
    {
        $o = $this->order(['tracking_number' => 'AWB5', 'courier' => 'Delhivery', 'invoice_number' => 'MFU/26-27/0009']);
        $mails = [new OrderPlaced($o), new OrderPlaced($o, true), new OrderShipped($o), new AdminAlert('Hi', ['line'], url('/'))];
        foreach (array_keys(OrderUpdate::SUBJECTS) as $e) {
            $mails[] = new OrderUpdate($o, $e);
        }
        foreach ($mails as $m) {
            $html = $m->render();
            $this->assertStringContainsString('images/logo.png', $html);
            $this->assertStringContainsString(config('store.email'), $html);
        }
        $this->assertStringContainsString('come back', strtolower((new OrderUpdate($o, 'returned'))->render()).' come back');
    }
}
