<?php

namespace Tests\Feature;

use App\Http\Controllers\Admin\SeoController;
use App\Models\Product;
use App\Models\User;
use App\Services\SeoFill;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SeoFillTest extends TestCase
{
    use RefreshDatabase;

    public function test_keyword_title_and_description_follow_the_rules(): void
    {
        [$core, $kw] = SeoFill::coreAndKeyword('Personalized Photo Magnet – 2×2 Inch Square (Set of 2)');
        $this->assertSame(['Personalized Photo Magnet', 'personalized photo magnet'], [$core, $kw]);
        $this->assertSame('custom 44mm round badge', SeoFill::keywordForSlug('Custom 44mm Round Badge | Photo Badge', 'custom-44mm-round-badge-photo', 'custom round badge'));
        $t = SeoFill::title('Personalized Coffee Mug');
        $this->assertTrue(mb_strlen($t) >= 30 && mb_strlen($t) <= 60);
        $d = SeoFill::description('Magic Photo Mug', 'A mug that reveals your photo when hot. Great gift.');
        $this->assertTrue(mb_strlen($d) >= 70 && mb_strlen($d) <= 160);
        $this->assertStringContainsString('A mug that reveals your photo when hot.', $d);
    }

    public function test_admin_button_fills_missing_fields_and_keeps_good_ones(): void
    {
        $this->seed(DemoSeeder::class);
        Product::query()->update(['focus_keyword' => null, 'meta_title' => null, 'meta_description' => null, 'meta_keywords' => null]);
        $kept = Product::first();
        $kept->update(['meta_description' => $good = 'Hand-written description that is long enough to pass the check and mentions nothing else at all here.']);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();

        $this->actingAs($admin)->get(route('admin.seo'))->assertSee('Auto-fill SEO');
        $this->post(route('admin.seo.fill'))->assertRedirect()->assertSessionHas('status');

        foreach (Product::with('images')->get() as $p) {
            $this->assertNotEmpty($p->focus_keyword);
            $this->assertNotEmpty($p->meta_keywords);
            $this->assertStringContainsString(mb_strtolower($p->focus_keyword), mb_strtolower($p->meta_title));
            $this->assertNotContains('No meta title set.', SeoController::score($p)['issues']);
        }
        // Kept only if it already contains the focus keyword; otherwise rewritten to include it.
        $kept->refresh();
        $this->assertTrue($kept->meta_description === $good || str_contains(mb_strtolower($kept->meta_description), mb_strtolower($kept->focus_keyword)));
        $this->get('/product/'.$kept->slug.'/')->assertSee('<meta name="keywords"', false);
    }
}
