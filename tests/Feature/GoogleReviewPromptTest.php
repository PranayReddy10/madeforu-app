<?php

namespace Tests\Feature;

use App\Http\Controllers\ReviewRequestController;
use App\Mail\ReviewRequest;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderNotifier;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GoogleReviewPromptTest extends TestCase
{
    use RefreshDatabase;

    private const G = 'https://g.page/r/CTestMadeForU/review';

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Mail::fake();
        Setting::put(['reviews.google_url' => self::G]);
        $this->useBrowserSession();
    }

    private function deliveredOrder(): Order
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $o = Order::create([
            'number' => 'MFU7101', 'name' => 'Asha Rao', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'status' => 'delivered', 'payment_method' => 'cod',
            'payment_status' => 'paid', 'subtotal' => 449, 'discount' => 0, 'shipping' => 60, 'cod_fee' => 0, 'total' => 509, 'delivered_at' => now(),
        ]);
        $o->items()->create(['product_id' => $mug->id, 'name' => 'Magic Photo Mug', 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        return $o;
    }

    public function test_order_review_leads_to_google_prompt_with_the_text_whatever_the_stars(): void
    {
        $order = $this->deliveredOrder();
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->post(ReviewRequestController::link($order), ['name' => 'Asha', 'reviews' => [$mug->id => ['rating' => 2, 'body' => 'Print was a bit dark.']]])
            ->assertRedirect(route('review.thanks'));
        $this->get(route('review.thanks'))->assertOk()
            ->assertSee('Would you share it on Google too?')->assertSee('Print was a bit dark.')
            ->assertSee('Copy my review')->assertSee(self::G, false);
    }

    public function test_product_page_review_also_leads_there(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->post(route('product.review', $mug), ['name' => 'Ravi', 'rating' => 5, 'body' => 'Lovely mug!'])
            ->assertRedirect(route('review.thanks'));
        $this->get(route('review.thanks'))->assertSee('Lovely mug!')->assertSee('will appear on our website once')->assertSee('Post on Google');
        $this->assertSame(1, Review::where('name', 'Ravi')->count());
    }

    public function test_without_a_google_link_it_is_a_plain_thank_you(): void
    {
        Setting::put(['reviews.google_url' => '']);
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->post(route('product.review', $mug), ['name' => 'Ravi', 'rating' => 5, 'body' => 'Lovely mug!']);
        $this->get(route('review.thanks'))->assertOk()->assertSee('Thank you for your review!')->assertDontSee('Post on Google');
        $this->get(route('review.thanks'))->assertOk();   // opened again later: still a page, no error
    }

    public function test_email_whatsapp_and_admin_ask_for_google_review(): void
    {
        $order = $this->deliveredOrder();
        (new ReviewRequest($order->load('items.product'), 'https://example.test/r'))
            ->assertSeeInHtml('Review us on Google too')->assertSeeInHtml(self::G, false)
            ->assertSeeInHtml('Tell us how your gift was — it takes a minute');   // the preview line (its section used to be left open)
        $this->assertStringContainsString(self::G, collect(OrderNotifier::waMessages($order))->firstWhere('label', 'Delivered — ask for a review')['text']);

        Review::create(['product_id' => Product::first()->id, 'order_id' => $order->id, 'name' => 'Asha Rao', 'rating' => 5, 'body' => 'Beautiful!', 'is_verified' => true, 'is_approved' => true]);
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.reviews.index'))->assertSee('Ask for Google review')->assertSee('https://wa.me/919876543210?text=', false);
    }
}
