<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\SalesReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SalesReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    private function order(string $no, array $over): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create($over + [
            'number' => $no, 'name' => 'Asha', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'subtotal' => 500, 'discount' => 0, 'shipping' => 0, 'cod_fee' => 0,
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 1, 'unit_price' => $o->total, 'line_total' => $o->total]);

        return $o;
    }

    private function seedOrders(): void
    {
        $this->order('ON1', ['status' => 'delivered', 'payment_method' => 'razorpay', 'payment_status' => 'paid', 'total' => 1000, 'delivered_at' => now()]);
        $this->order('COD1', ['status' => 'delivered', 'payment_method' => 'cod', 'payment_status' => 'paid', 'total' => 600, 'delivered_at' => now()]);
        $this->order('COD2', ['status' => 'delivered', 'payment_method' => 'cod', 'payment_status' => 'paid', 'total' => 400, 'delivered_at' => now(), 'cod_remitted_at' => now()]);
        $this->order('OLD', ['status' => 'delivered', 'payment_method' => 'cod', 'payment_status' => 'paid', 'total' => 250, 'delivered_at' => now()->subMonths(3)]);
        $this->order('SHIP', ['status' => 'shipped', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'total' => 700]);
        $this->order('MAKE', ['status' => 'in_production', 'payment_method' => 'razorpay', 'payment_status' => 'paid', 'total' => 300]);
        $this->order('RTO', ['status' => 'returned', 'payment_method' => 'cod', 'payment_status' => 'unpaid', 'total' => 450]);
    }

    public function test_summary_splits_online_cod_and_cod_due(): void
    {
        $this->seedOrders();
        $s = (new SalesReport('month'))->summary();
        $this->assertEquals(2000, $s['sales']['value']);
        $this->assertSame(3, $s['sales']['count']);
        $this->assertEquals(1000, $s['online']['value']);
        $this->assertEquals(1000, $s['cod']['value']);
        $this->assertEquals(400, $s['cod_received']['value']);
        $this->assertEquals(850, $s['cod_due']['value']);    // COD1 600 + OLD 250, any date
        $this->assertEquals(700, $s['open_cod']['value']);
        $this->assertEquals(300, $s['open_online']['value']);
        $this->assertEquals(450, $s['returned']['value']);
        $this->assertEquals(2250, (new SalesReport('custom', now()->subMonths(4)->format('Y-m-d'), now()->format('Y-m-d')))->summary()['sales']['value']);
    }

    public function test_page_export_and_marking_cod_received(): void
    {
        $this->seedOrders();
        $this->actingAs($this->admin)->get('/admin/sales')->assertOk()
            ->assertSee('Sales (delivered)')->assertSee('₹2,000')->assertSee('COD still to receive from Delhivery')->assertSee('₹850')
            ->assertSee('COD1')->assertDontSee('>SHIP<', false);
        $this->actingAs($this->admin)->get('/admin/sales?method=online')->assertOk()->assertSee('ON1')->assertSee('Delivered orders (1)');

        $csv = $this->actingAs($this->admin)->get('/admin/sales/export.csv')->assertOk()->streamedContent();
        $this->assertStringContainsString('"Delivered on",Invoice,Order,Customer', $csv);
        $this->assertStringContainsString(',ON1,', $csv);
        $this->assertStringContainsString('Not yet', $csv);

        $ids = Order::whereIn('number', ['COD1', 'OLD', 'ON1'])->pluck('id')->all();
        $this->actingAs($this->admin)->post('/admin/sales/cod-received', ['ids' => $ids, 'received_on' => today()->format('Y-m-d'), 'ref' => 'UTR123'])
            ->assertSessionHas('status', fn ($m) => str_contains($m, '2 COD orders (₹850)'));
        $this->assertSame('UTR123', Order::where('number', 'COD1')->value('cod_remittance_ref'));
        $this->assertNull(Order::where('number', 'ON1')->value('cod_remitted_at'));    // online orders untouched
        $this->assertEquals(0, (new SalesReport('month'))->summary()['cod_due']['value']);
    }

    public function test_financial_year_starts_in_april(): void
    {
        $this->travelTo('2026-02-10');
        $this->assertSame('2025-04-01', (new SalesReport('fy'))->from->format('Y-m-d'));
        $this->travelTo('2026-11-05');
        $this->assertSame('2026-04-01', (new SalesReport('fy'))->from->format('Y-m-d'));
    }
}
