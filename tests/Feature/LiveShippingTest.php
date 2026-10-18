<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\Parcel;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Factory;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class LiveShippingTest extends TestCase
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
        Setting::put(['delhivery.enabled' => '1', 'delhivery.auto' => '0', 'delhivery.token' => 'dl-token', 'delhivery.pickup_location' => 'madeforu',
            'delhivery.pickup_pincode' => '500032', 'delhivery.mode' => 'production', 'delhivery.check_pincodes' => '0',
            'shipping.mode' => 'live', 'shipping.extra' => '10', 'shipping.round_to' => '5', 'shipping.cod_mode' => 'delhivery',
            'shipping_fee' => '60', 'free_shipping_over' => '99999', 'cod_fee' => '40', 'cod_fee_percent' => '0']);
        // Delhivery: ₹63.20 prepaid, ₹96.80 with COD.
        Http::fake(['track.delhivery.com/api/kinko/v1/invoice/charges/*' => fn ($r) => Http::response([['total_amount' => $r['pt'] === 'COD' ? 96.8 : 63.2]])]);
    }

    private function mug(): Product
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $mug->update(['weight_kg' => 0.4, 'length_cm' => 12, 'width_cm' => 12, 'height_cm' => 12, 'packaging' => 'box']);

        return $mug;
    }

    private function fillCart(int $qty = 1): void
    {
        $mug = $this->mug();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['qty' => $qty, 'custom' => [$photo->id => [$token]]]);
    }

    public function test_parcel_bills_the_higher_of_actual_and_volumetric_weight(): void
    {
        $mug = $this->mug();
        $p = Parcel::of([[$mug, 2]]);
        $this->assertSame(800, $p->grams);
        $this->assertEquals([12, 12, 24], [$p->length, $p->width, $p->height]);
        $this->assertSame(692, $p->volumetricGrams());      // 12 × 12 × 24 / 5
        $this->assertSame(800, $p->chargeableGrams());
        $this->assertSame('box', $p->packaging);

        $mug->update(['height_cm' => 30]);
        $this->assertSame(1728, Parcel::of([[$mug->fresh(), 2]])->chargeableGrams());
    }

    public function test_checkout_quote_uses_delhivery_rate_for_the_pin(): void
    {
        $this->fillCart();
        $q = $this->getJson(route('checkout.quote', ['pin' => '500001']))->assertOk();
        // 63.20 + 10 handling → rounded up to ₹75; COD = 96.80 − 63.20 = 33.60 → ₹35.
        $this->assertTrue($q->json('online.live'));
        $this->assertStringContainsString('Delhivery rate to your PIN', $q->json('online.html'));
        $online = (float) $q->json('online.total');
        $cod = (float) $q->json('cod.total');
        $this->assertEqualsWithDelta(35, $cod - $online, 0.01);
        Http::assertSent(fn ($r) => str_contains($r->url(), 'kinko') && $r['o_pin'] === '500032' && $r['d_pin'] === '500001' && (int) $r['cgm'] === 400 && $r['md'] === 'S');
    }

    public function test_order_is_charged_the_live_rate(): void
    {
        $this->fillCart();
        $this->post(route('checkout.place'), self::ADDRESS + ['payment_method' => 'cod'])->assertRedirect();
        $order = Order::firstOrFail();
        $this->assertEquals(75, $order->shipping);
        $this->assertEquals(35, $order->cod_fee);
    }

    public function test_falls_back_to_flat_fee_when_delhivery_has_no_rate(): void
    {
        Http::swap(new Factory);
        Http::fake(['track.delhivery.com/*' => Http::response('error', 500)]);
        $this->fillCart();
        $q = $this->getJson(route('checkout.quote', ['pin' => '500001']))->assertOk();
        $this->assertFalse($q->json('online.live'));
        $this->assertStringContainsString('estimate', $q->json('online.html'));
    }

    public function test_product_page_pin_check_shows_the_delivery_charge(): void
    {
        Http::fake(['track.delhivery.com/c/api/pin-codes/*' => Http::response(['delivery_codes' => [['postal_code' => ['pin' => 500001, 'district' => 'Hyderabad', 'pre_paid' => 'Y', 'cod' => 'Y']]]])]);
        Setting::put(['cod_enabled' => '1']);
        $mug = $this->mug();
        $r = $this->getJson(route('delivery.check', ['pin' => '500001', 'item' => $mug->id]))->assertOk()->assertJson(['ok' => true, 'charge' => 75]);
        $this->assertStringContainsString('Delivery charge ₹75 (+₹35 for cash on delivery)', $r->json('message'));
        $this->get('/product/'.$mug->slug.'/')->assertSee('item='.$mug->id, false);
    }

    public function test_admin_saves_packed_size_and_product_page_shows_it(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('admin.products.edit', $mug))->assertOk()->assertSee('name="length_cm"', false)->assertSee('Carton box');
        $this->put(route('admin.products.update', $mug), [
            'name' => $mug->name, 'slug' => $mug->slug, 'type' => $mug->type, 'price' => $mug->price,
            'weight_g' => 450, 'length_cm' => 15, 'width_cm' => 12, 'height_cm' => 11, 'packaging' => 'flyer', 'is_active' => 1, 'in_stock' => 1,
        ])->assertSessionHasNoErrors();
        $mug->refresh();
        $this->assertEquals(0.45, (float) $mug->weight_kg);
        $this->get(route('admin.products.edit', $mug))->assertSee('name="weight_g"', false)->assertSee('value="450"', false);
        $this->put(route('admin.products.update', $mug), ['name' => $mug->name, 'slug' => $mug->slug, 'type' => $mug->type, 'price' => $mug->price, 'weight_g' => 450, 'length_cm' => 250])
            ->assertSessionHasErrors(['length_cm' => 'Sizes are in cm — for 120 mm enter 12.']);
        $this->assertEquals([15, 12, 11, 'flyer'], [(float) $mug->length_cm, (float) $mug->width_cm, (float) $mug->height_cm, $mug->packaging]);
        $this->get('/product/'.$mug->slug.'/')->assertOk()->assertSee('15 × 12 × 11 cm')->assertSee('450 g')->assertSee('padded courier flyer');
    }

    public function test_weight_typed_in_grams_and_size_in_mm_are_not_quoted_as_huge_parcels(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $mug->update(['weight_kg' => 350, 'length_cm' => 250, 'width_cm' => 200, 'height_cm' => 160]);   // grams and mm by mistake
        $p = Parcel::of([[$mug, 1]]);
        $this->assertSame(350, $p->grams);
        $this->assertEquals([25, 20, 16], [$p->length, $p->width, $p->height]);
        $this->assertSame(1600, $p->chargeableGrams());   // 25 × 20 × 16 ÷ 5, not 250 × 200 × 160
    }

    public function test_admin_rate_tester_shows_parcel_and_delhivery_reply(): void
    {
        $mug = $this->mug();
        $this->actingAs(User::factory()->create(['is_admin' => true]));
        $this->get(route('admin.products.edit', $mug))->assertSee('Test the delivery charge');
        $this->getJson(route('admin.products.rate', [$mug, 'pin' => '500001', 'qty' => 2]))->assertOk()
            ->assertJsonPath('parcel.actual_g', 800)->assertJsonPath('parcel.chargeable_g', 800)
            ->assertJsonPath('prepaid.total', 63.2)->assertJsonPath('prepaid.sent.cgm', 800)->assertJsonPath('cod.total', 96.8)
            ->assertJsonPath('customer_pays.shipping', 75);
        $this->actingAs(User::factory()->create())->getJson(route('admin.products.rate', [$mug, 'pin' => '500001']))->assertForbidden();
    }
}
