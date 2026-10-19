<?php

namespace Tests\Feature;

use App\Mail\AdminAlert;
use App\Models\Category;
use App\Models\ContactMessage;
use App\Models\Page;
use App\Models\Product;
use App\Models\User;
use App\Support\DefaultPages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\TestCase;

class PagesAndHomeTest extends TestCase
{
    use RefreshDatabase;

    public function test_policy_about_and_contact_pages_have_their_layout_and_content(): void
    {
        // The migration already created them; each layout renders its own pieces.
        $this->get('/about-us/')->assertOk()->assertSee('Our story')->assertSee('How we work')->assertSee('No giant warehouse');
        $this->get('/contact-us/')->assertOk()->assertSee('Send a message')->assertSee('+91 93810 24794')->assertSee('support@madeforu.co.in');
        $this->get('/legal/')->assertOk()->assertSee('Refund &amp; Cancellation Policy', false)->assertSee('Privacy Policy');
        $this->get('/legal/refund-cancellation-policy/')->assertOk()
            ->assertSee('48 hours of delivery', false)->assertSee('Last updated')
            ->assertSee('class="legal-nav"', false)->assertSee('/legal/shipping-delivery-policy/', false);
        $this->get('/legal/terms-and-condition/')->assertOk()->assertDontSee('/refund-returns/', false);
    }

    public function test_imported_page_builder_markup_is_replaced_but_edits_are_kept(): void
    {
        Page::where('slug', 'about-us')->update(['body' => '<div data-element_type="container"><h1>About</h1></div>']);
        Page::where('slug', 'contact-us')->update(['body' => '<p>Our own words.</p>']);

        DefaultPages::apply();

        $this->assertStringContainsString('No giant warehouse', Page::where('slug', 'about-us')->value('body'));
        $this->assertSame('<p>Our own words.</p>', Page::where('slug', 'contact-us')->value('body'));
    }

    public function test_contact_form_saves_the_message_and_emails_the_shop(): void
    {
        Mail::fake();

        $this->from('/contact-us/')->post('/contact-message', ['name' => 'Asha', 'phone' => '98765 43210', 'topic' => 'Custom or bulk order', 'message' => 'Need 50 mugs for a wedding.'])
            ->assertRedirect('/contact-us/')->assertSessionHas('contact_sent');
        $this->assertSame('9876543210', ContactMessage::first()->phone);
        Mail::assertSent(AdminAlert::class, fn ($m) => $m->title === 'Message from Asha (98765 43210) — Custom or bulk order'
            && str_contains(implode(' ', $m->lines), 'https://wa.me/919876543210') && $m->hasTo('support@madeforu.co.in'));

        // The mobile number is required (email alone isn't enough), and bots filling the hidden field are turned away.
        $this->post('/contact-message', ['name' => 'X', 'message' => 'Hello there'])->assertSessionHasErrors('phone');
        $this->post('/contact-message', ['name' => 'X', 'email' => 'x@x.com', 'message' => 'Hello there'])->assertSessionHasErrors('phone');
        $this->post('/contact-message', ['name' => 'X', 'phone' => '12345', 'message' => 'Hello there'])->assertSessionHasErrors('phone');
        $this->post('/contact-message', ['name' => 'Bot', 'phone' => '9876543210', 'message' => 'Buy now', 'website' => 'spam'])->assertSessionHasErrors('website');
        $this->assertSame(1, ContactMessage::count());

        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get('/admin/messages/')->assertOk()->assertSee('Need 50 mugs for a wedding.');
    }

    public function test_home_shows_category_rows_budget_tiles_offers_and_faqs(): void
    {
        $cat = Category::create(['name' => 'Magnets', 'slug' => 'magnets', 'is_active' => true, 'show_on_home' => true]);
        foreach ([['Heart Magnet', 149, null], ['Square Magnet', 399, 299], ['Collage Magnet', 799, null]] as [$name, $price, $sale]) {
            $p = Product::create(['name' => $name, 'slug' => Str::slug($name), 'price' => $price, 'sale_price' => $sale, 'is_active' => true, 'in_stock' => true, 'type' => 'simple']);
            $p->categories()->attach($cat);
        }

        $this->get('/')->assertOk()
            ->assertSee('Gifts for every budget')->assertSee('?max=199', false)
            ->assertSee('3 designs')                     // the category gets its own row
            ->assertSee('Offers you’ll love')->assertSee('Square Magnet')
            ->assertSee('Questions, answered')->assertSee('"FAQPage"', false);
    }

    public function test_categories_can_be_left_out_of_the_top_menu(): void
    {
        $product = Product::create(['name' => 'Mug', 'slug' => 'mug', 'price' => 299, 'is_active' => true, 'in_stock' => true, 'type' => 'simple']);
        foreach (['Mugs' => true, 'Custom Mugs' => false] as $name => $inMenu) {
            Category::create(['name' => $name, 'slug' => Str::slug($name), 'is_active' => true, 'show_in_menu' => $inMenu])->products()->attach($product);
        }

        $html = $this->get('/')->assertOk()->getContent();
        $top = Str::betweenFirst($html, 'class="cat-nav"', '</nav>');
        $this->assertStringContainsString('>Mugs<', $top);
        $this->assertStringNotContainsString('Custom Mugs', $top);
        $this->assertStringContainsString('data-nav-more', $top);
        $this->assertStringContainsString('Custom Mugs', $html);   // still in the side menu
    }
}
