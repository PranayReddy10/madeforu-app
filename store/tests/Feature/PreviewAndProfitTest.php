<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\ProfitReport;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PreviewAndProfitTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->forceFill(['is_admin' => true])->save();

        return $a;
    }

    public function test_live_preview_switches_per_product_and_for_the_whole_shop(): void
    {
        $this->seed(DemoSeeder::class);
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->get($mug->url())->assertDontSee('data-live-preview', false);

        $this->actingAs($this->admin())->put(route('admin.products.update', $mug), [
            'name' => $mug->name, 'slug' => $mug->slug, 'type' => 'simple', 'price' => $mug->price, 'is_active' => 1, 'in_stock' => 1, 'cost_price' => 120,
            'fields' => $mug->customizationFields->map(fn ($f) => ['id' => $f->id, 'type' => $f->type, 'label' => $f->label, 'min_files' => $f->min_files, 'max_files' => $f->max_files, 'required' => $f->required ? 1 : null])->all(),
            'preview' => ['on' => 1, 'x' => 30, 'y' => 20, 'w' => 40, 'h' => 45, 'shape' => 'circle', 'blend' => 'multiply'],
        ])->assertSessionHasNoErrors();
        $mug->refresh();
        $this->assertEquals(120, $mug->cost_price);
        $this->assertSame('circle', $mug->livePreview()['shape']);
        $this->get(route('admin.products.edit', $mug))->assertSee('Live preview')->assertSee('left:30%', false);

        auth()->logout();
        $this->get($mug->url())->assertSee('data-live-preview', false)->assertSee('left:30%;top:20%;width:40%;height:45%', false)->assertSee('blend-multiply', false);

        Setting::put(['preview.enabled' => '0']);
        $this->get($mug->url())->assertDontSee('data-live-preview', false);
    }

    public function test_profit_counts_making_packing_courier_fees_and_returns(): void
    {
        Setting::put(['profit.packing' => '10', 'profit.gateway_percent' => '2', 'profit.shipping_mode' => 'fixed', 'profit.shipping_fixed' => '60']);
        $p = Product::create(['name' => 'Mug', 'slug' => 'mug', 'type' => 'simple', 'price' => 300, 'cost_price' => 100, 'is_active' => true, 'in_stock' => true]);
        $make = function (string $status, string $method, string $pay, ?float $unitCost) use ($p) {
            $o = Order::create(['number' => 'MFU'.random_int(1000, 99999), 'status' => $status, 'payment_method' => $method, 'payment_status' => $pay,
                'subtotal' => 600, 'shipping' => 0, 'total' => 600, 'name' => 'A', 'email' => 'a@x.com', 'phone' => '9876543210',
                'address_line1' => 'x', 'city' => 'Hyd', 'state' => 'Telangana', 'pincode' => '500001']);
            $o->items()->create(['product_id' => $p->id, 'name' => 'Mug', 'qty' => 2, 'unit_price' => 300, 'unit_cost' => $unitCost, 'line_total' => 600]);
        };
        $make('delivered', 'razorpay', 'paid', 90);    // cost saved at checkout: 90
        $make('confirmed', 'cod', 'unpaid', null);     // older order: today's product cost 100
        $make('returned', 'cod', 'unpaid', null);      // lost: 200 making + 10 packing + 2×60 courier
        $make('cancelled', 'cod', 'unpaid', null);     // ignored

        $s = (new ProfitReport(30))->summary();
        $this->assertSame(2, $s['orders']);
        $this->assertEquals(1200, $s['revenue']);
        $this->assertEquals(180 + 200, $s['making']);
        $this->assertEquals(20, $s['packing']);
        $this->assertEquals(120, $s['courier']);
        $this->assertEquals(12, $s['fees']);              // 2% of the online order only
        $this->assertEquals(330, $s['returns']);
        $this->assertEquals(1200 - 380 - 20 - 120 - 12 - 330, $s['profit']);

        $this->actingAs($this->admin())->get(route('admin.profit'))->assertOk()->assertSee('₹338')->assertSee('Returned parcels (1)');
        $this->get('/admin/')->assertSee('Profit, last 30 days');
    }

    public function test_checkout_saves_the_cost_on_the_order_line(): void
    {
        $this->seed(DemoSeeder::class);
        $this->useBrowserSession();
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $mug->update(['cost_price' => 95]);
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => \Illuminate\Http\UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token]]]);
        \Illuminate\Support\Facades\Mail::fake();
        $this->post(route('checkout.place'), ['name' => 'Asha', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => '12 MG Road',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'payment_method' => 'cod'])->assertRedirect();
        $this->assertEquals(95, Order::latest('id')->first()->items->first()->unit_cost);
    }
}
