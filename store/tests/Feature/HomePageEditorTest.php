<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Product;
use App\Models\User;
use App\Support\HomeLayout;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class HomePageEditorTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);
    }

    /** The editor's form data for the current layout, keyed by section id, as the browser sends it. */
    private function form(?callable $edit = null): array
    {
        $sections = HomeLayout::get();
        if ($edit) {
            $sections = $edit($sections);
        }

        return ['sections' => collect($sections)->keyBy('id')->map(fn ($s) => array_merge($s, ['on' => $s['on'] ? '1' : '0']))->all()];
    }

    public function test_editor_lists_every_section_of_the_standard_home_page(): void
    {
        $this->get('/admin/home-page/')->assertOk()
            ->assertSee('Top slider')->assertSee('Shop by category')->assertSee('Bestsellers')->assertSee('Questions &amp; answers', false)
            ->assertSee('data-hb-template="promo"', false);
        $this->get('/')->assertOk()->assertSee('Shop by category')->assertSee('Made for you in three steps')->assertSee('"FAQPage"', false);
    }

    public function test_sections_can_be_renamed_reordered_switched_off_and_added(): void
    {
        $data = $this->form(function ($sections) {
            $sections = collect($sections);
            $faq = $sections->firstWhere('type', 'faq');
            $steps = $sections->firstWhere('type', 'steps');
            $rest = $sections->reject(fn ($s) => in_array($s['type'], ['faq', 'steps'], true))->map(function ($s) {
                if ($s['type'] === 'products' && $s['source'] === 'bestsellers') {
                    $s['title'] = 'Most loved gifts';
                }

                return $s;
            });
            $faq['on'] = false;
            $faq['items'] = [['q' => 'Do you gift wrap?', 'a' => 'Yes, free.']];
            $steps['title'] = 'How it works';

            return collect([$steps])->concat($rest)->push($faq)
                ->push(['id' => 'newtext1', 'type' => 'text', 'on' => true, 'title' => 'Diwali gifting is here', 'body' => '<p>Order by 25 Oct.</p>'])
                ->push(['id' => 'dup1', 'type' => 'slider', 'on' => true])   // single-use: a second slider is dropped
                ->all();
        });
        $this->put('/admin/home-page/', $data)->assertRedirect()->assertSessionHas('status');

        $types = collect(HomeLayout::get())->pluck('type');
        $this->assertSame('steps', $types->first());
        $this->assertSame(1, $types->filter(fn ($t) => $t === 'slider')->count());

        $html = $this->get('/')->assertOk()->assertSee('Most loved gifts')->assertSee('Diwali gifting is here')->assertSee('Order by 25 Oct.', false)
            ->assertDontSee('Do you gift wrap?')->assertDontSee('"FAQPage"', false)->getContent();
        $this->assertLessThan(strpos($html, 'Most loved gifts'), strpos($html, 'How it works'));
    }

    public function test_product_rows_from_a_category_or_hand_picked_and_a_promo_banner(): void
    {
        $lamp = Product::where('slug', '3d-moon-photo-lamp')->first();
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $cat = Category::whereHas('products', fn ($q) => $q->whereKey($lamp->id))->first();
        $data = $this->form(fn ($sections) => array_merge($sections, [
            ['id' => 'picked01', 'type' => 'products', 'on' => true, 'title' => 'Staff picks', 'subtitle' => '', 'source' => 'manual', 'product_ids' => [$mug->id, $lamp->id], 'limit' => 8],
            ['id' => 'catrow01', 'type' => 'products', 'on' => true, 'title' => 'Lamps we love', 'subtitle' => '', 'source' => 'category', 'category_id' => $cat->id, 'limit' => 8],
            ['id' => 'promo001', 'type' => 'promo', 'on' => true, 'image' => 'https://cdn.example.com/diwali.jpg', 'mobile_image' => '', 'title' => 'Diwali Sale', 'text' => '20% off', 'button' => 'Shop now', 'link' => '/shop/?sale=1'],
        ]));
        $this->put('/admin/home-page/', $data)->assertRedirect();

        $html = $this->get('/')->assertOk()->assertSee('Staff picks')->assertSee('Lamps we love')
            ->assertSee('https://cdn.example.com/diwali.jpg')->assertSee('Diwali Sale')->getContent();
        $picks = Str::betweenFirst($html, 'Staff picks', '</section>');
        $this->assertLessThan(strpos($picks, $lamp->name), strpos($picks, $mug->name));   // the picked order is kept
        $this->assertStringContainsString($cat->url(), $html);                          // "View all" goes to the category

        $this->put('/admin/home-page/', ['reset' => 1])->assertRedirect();
        $this->get('/')->assertDontSee('Staff picks')->assertSee('Bestsellers');
    }
}
