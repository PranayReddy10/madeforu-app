<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class CampaignAndWhatsappTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow('2026-10-20 10:00:00');
        $this->seed(DemoSeeder::class);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admin(): User
    {
        $a = User::factory()->create();
        $a->forceFill(['is_admin' => true])->save();

        return $a;
    }

    private function diwali(array $extra = []): void
    {
        $this->actingAs($this->admin())->put(route('admin.campaign.update'), $extra + [
            'enabled' => 1, 'name' => 'Diwali', 'festival' => '2026-11-08', 'deadline' => '2026-11-02',
            'headline' => 'Light up their Diwali', 'coupon' => 'diwali10', 'discount' => 10, 'min_order' => 499,
        ])->assertSessionHasNoErrors();
        auth()->logout();
    }

    public function test_campaign_shows_strip_landing_page_product_note_and_makes_the_coupon(): void
    {
        $this->get('/')->assertDontSee('camp-strip', false);
        $this->diwali();

        $c = Coupon::where('code', 'DIWALI10')->first();
        $this->assertEquals([10, 499], [(float) $c->value, (float) $c->min_subtotal]);
        $this->assertSame('2026-11-08 23:59:59', $c->expires_at->format('Y-m-d H:i:s'));

        $this->get('/')->assertSee('camp-strip', false)->assertSee('order by Mon, 2 Nov', false)->assertSee('DIWALI10')->assertSee('hs-camp', false);
        $this->get('/diwali-gifts/')->assertOk()->assertSee('Light up their Diwali')->assertSee('data-countdown', false)->assertSee('tap to copy');
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->get($mug->url())->assertSee('arrives before Diwali (8 Nov)');

        // After the order-by date: last-minute message; after the festival: everything gone
        Carbon::setTestNow('2026-11-05 10:00:00');
        $this->get('/')->assertSee('Last-minute Diwali gift?');
        $this->get($mug->url())->assertSee('may arrive after Diwali');
        Carbon::setTestNow('2026-11-09 10:00:00');
        $this->get('/')->assertDontSee('camp-strip', false);
        $this->get('/diwali-gifts/')->assertNotFound();
    }

    public function test_whatsapp_list_merges_orders_by_phone_and_downloads(): void
    {
        $this->diwali();
        $mk = fn ($name, $phone, $status, $total, $when) => Order::create(['number' => 'MFU'.random_int(1000, 99999), 'status' => $status, 'payment_method' => 'cod',
            'payment_status' => 'unpaid', 'subtotal' => $total, 'total' => $total, 'name' => $name, 'email' => 'x@x.com', 'phone' => $phone,
            'address_line1' => 'x', 'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001'])->forceFill(['created_at' => $when])->save();
        $mk('Asha Rao', '+91 98765 43210', 'delivered', 300, now()->subDays(200));
        $mk('Asha Rao', '09876543210', 'delivered', 200, now()->subDays(10));   // same person, other format
        $mk('Ravi', '9123456789', 'cancelled', 500, now()->subDays(5));          // only cancelled → not a buyer
        $mk('Bad', '12345', 'delivered', 100, now()->subDays(5));                // not a mobile number

        $admin = $this->admin();
        $r = $this->actingAs($admin)->get(route('admin.users.whatsapp'))->assertOk()
            ->assertSee('+91 9876543210')->assertSee('₹500')->assertDontSee('9123456789')->assertSee('DIWALI10')->assertSee('diwali-gifts');
        $this->assertSame(1, substr_count($r->getContent(), 'data-wa-row='));
        $this->get(route('admin.users.whatsapp', ['show' => 'all']))->assertSee('9123456789');
        $csv = $this->get(route('admin.users.whatsapp', ['download' => 1]))->assertOk()->streamedContent();
        $this->assertStringContainsString('"Asha Rao",+919876543210,Hyderabad,2,500', $csv);

        $this->post(route('admin.users.whatsapp.message'), ['message' => 'Hi {name}! {link}'])->assertSessionHas('status');
        $this->get(route('admin.users.whatsapp'))->assertSee('Hi {name}! {link}');
    }
}
