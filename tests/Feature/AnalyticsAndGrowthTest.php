<?php

namespace Tests\Feature;

use App\Models\Coupon;
use App\Models\Product;
use App\Models\Setting;
use App\Models\User;
use App\Services\WooImporter;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AnalyticsAndGrowthTest extends TestCase
{
    use RefreshDatabase;

    private const PHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 Mobile/15E148';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
    }

    private function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_admin' => true])->save();

        return $u;
    }

    public function test_visits_are_counted_with_their_source_but_not_bots_or_admins(): void
    {
        $p = Product::active()->first();
        $this->withHeaders(['User-Agent' => self::PHONE, 'Referer' => 'https://l.instagram.com/'])->get('/')->assertOk();
        $this->withHeaders(['User-Agent' => self::PHONE])->get($p->url())->assertOk();
        $this->withHeaders(['User-Agent' => 'Googlebot/2.1'])->get('/')->assertOk();
        $this->actingAs($this->admin())->withHeaders(['User-Agent' => self::PHONE])->get('/');

        $rows = DB::table('analytics_events')->get();
        $this->assertCount(2, $rows);
        $this->assertSame(['Instagram'], $rows->pluck('source')->unique()->values()->all());   // the source sticks for the visit
        $this->assertSame($p->id, (int) $rows->firstWhere('type', 'product')->product_id);
        $this->assertSame('mobile', $rows[0]->device);
    }

    public function test_analytics_page_shows_shop_numbers_and_google_reports(): void
    {
        DB::table('analytics_events')->insert([
            ['day' => today()->toDateString(), 'visitor' => 'aaaaaaaaaaaaaaaa', 'type' => 'view', 'path' => '/', 'source' => 'Google', 'device' => 'mobile', 'created_at' => now()],
            ['day' => today()->toDateString(), 'visitor' => 'aaaaaaaaaaaaaaaa', 'type' => 'cart', 'path' => '/cart/add/1', 'source' => 'Google', 'device' => 'mobile', 'created_at' => now()],
            ['day' => today()->toDateString(), 'visitor' => 'bbbbbbbbbbbbbbbb', 'type' => 'view', 'path' => '/', 'source' => 'WhatsApp', 'device' => 'desktop', 'created_at' => now()],
        ]);
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        Setting::put([
            'google.service_account' => json_encode(['client_email' => 'reports@shop.iam.gserviceaccount.com', 'private_key' => $pem]),
            'google.ga4_property' => '412345678',
            'google.search_console_site' => 'sc-domain:madeforu.co.in',
        ]);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'analyticsdata.googleapis.com/*' => Http::response(['rows' => [['dimensionValues' => [['value' => 'Organic Search']], 'metricValues' => [['value' => '321'], ['value' => '300'], ['value' => '900'], ['value' => '0.61'], ['value' => '75'], ['value' => '9']]]]]),
            'www.googleapis.com/webmasters/v3/sites/*/searchAnalytics/query' => Http::response(['rows' => [['keys' => ['personalised photo magnet'], 'clicks' => 42, 'impressions' => 1200, 'ctr' => 0.035, 'position' => 6.2]]]),
            'www.googleapis.com/webmasters/v3/sites/*/sitemaps' => Http::response(['sitemap' => [['path' => 'https://madeforu.co.in/sitemap.xml', 'lastDownloaded' => now()->subDay()->toIso8601String()]]]),
        ]);

        $this->actingAs($this->admin())->get('/admin/analytics/')->assertOk()
            ->assertSee('Shopping funnel')->assertSee('WhatsApp')
            ->assertSee('Organic Search')->assertSee('personalised photo magnet')->assertSee('Submit sitemap to Google');
        Http::assertSent(fn ($r) => str_contains($r->url(), 'properties/412345678:runReport'));
        $this->get('/admin/')->assertOk()->assertSee('visitors this week');
    }

    public function test_index_check_stores_what_google_says(): void
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048]);
        openssl_pkey_export($key, $pem);
        Setting::put(['google.service_account' => json_encode(['client_email' => 'r@x.iam.gserviceaccount.com', 'private_key' => $pem]), 'google.search_console_site' => 'sc-domain:madeforu.co.in']);
        Http::fake([
            'oauth2.googleapis.com/*' => Http::response(['access_token' => 'tok']),
            'searchconsole.googleapis.com/*' => Http::sequence()->push(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'PASS', 'coverageState' => 'Submitted and indexed', 'lastCrawlTime' => '2026-10-01T10:00:00Z']]])
                ->whenEmpty(Http::response(['inspectionResult' => ['indexStatusResult' => ['verdict' => 'NEUTRAL', 'coverageState' => 'Discovered - currently not indexed']]])),
        ]);

        $this->artisan('store:index-check', ['--limit' => 3])->assertSuccessful();

        $this->assertSame(3, DB::table('search_index_status')->whereNotNull('checked_at')->count());
        $this->assertSame(1, DB::table('search_index_status')->where('verdict', 'PASS')->count());
        $this->actingAs($this->admin())->get('/admin/analytics/')->assertSee('Not on Google yet')->assertSee('Discovered - currently not indexed');
    }

    public function test_coupons_are_imported_from_woocommerce(): void
    {
        Http::fake(['shop.test/wp-json/wc/v3/coupons*' => Http::response([
            ['code' => 'diwali20', 'discount_type' => 'percent', 'amount' => '20.00', 'minimum_amount' => '499.00', 'usage_limit' => 100, 'usage_count' => 7, 'date_expires' => '2026-11-15T00:00:00', 'status' => 'publish'],
            ['code' => 'flat50', 'discount_type' => 'fixed_cart', 'amount' => '50', 'minimum_amount' => '', 'usage_limit' => null, 'usage_count' => 0, 'date_expires' => null, 'status' => 'draft'],
        ])]);

        $importer = new WooImporter('https://shop.test', 'ck_x', 'cs_x');
        $importer->importCoupons();
        $importer->importCoupons();   // again: updates, no duplicates

        $d = Coupon::where('code', 'DIWALI20')->first();
        $this->assertSame(['percent', 20.0, 499.0, 100, 7], [$d->type, (float) $d->value, (float) $d->min_subtotal, $d->usage_limit, $d->used]);
        $this->assertSame('2026-11-15', $d->expires_at->toDateString());
        $this->assertFalse((bool) Coupon::where('code', 'FLAT50')->value('is_active'));
        $this->assertSame(3, Coupon::count());   // + the demo WELCOME10
    }

    public function test_home_shows_instagram_posts_or_a_follow_band(): void
    {
        $this->get('/')->assertSee('Follow us on Instagram')->assertSee('@madeforu_gifts');

        Setting::put(['instagram.token' => 'IGtoken']);
        Http::fake(['graph.instagram.com/me/media*' => Http::response(['data' => [
            ['id' => '1', 'media_type' => 'IMAGE', 'media_url' => 'https://cdn.ig/1.jpg', 'permalink' => 'https://www.instagram.com/p/abc/', 'caption' => 'New magnets!'],
        ]])]);
        $this->get('/')->assertSee('https://cdn.ig/1.jpg')->assertSee('https://www.instagram.com/p/abc/');
    }

    public function test_cart_invites_guests_to_create_an_account_and_returns_them(): void
    {
        $p = Product::where('slug', '3d-moon-photo-lamp')->first();
        $p->customizationFields()->delete();
        $this->useBrowserSession();
        $this->post(route('cart.add', $p), ['qty' => 1]);

        $this->get('/cart')->assertSee('Create a free account')->assertSee('next=cart', false);
        $this->get('/register?next=cart')->assertOk();
        $this->post('/register', ['name' => 'Asha', 'email' => 'asha@example.com', 'phone' => '9876543210', 'password' => 'secret123', 'password_confirmation' => 'secret123'])
            ->assertRedirect(route('cart'));
        $this->get('/cart')->assertDontSee('Create a free account')->assertSee('3D Moon Photo Lamp');
    }
}
