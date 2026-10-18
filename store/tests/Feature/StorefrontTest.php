<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Coupon;
use App\Models\CustomerUpload;
use App\Models\Order;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\User;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class StorefrontTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
    }

    public function test_woocommerce_urls_resolve_with_trailing_slash(): void
    {
        $this->get('/product/magic-photo-mug/')->assertOk()->assertSee('Magic Photo Mug');
        $this->get('/product/magic-photo-mug')->assertRedirect('http://localhost/product/magic-photo-mug/')->assertStatus(301);
        $this->get('/product-category/photo-frames/wall-frames/')->assertOk()->assertSee('Personalised Wooden Photo Frame');
        // WooCommerce also answers a nested category by its own slug
        $this->get('/product-category/wall-frames/')->assertRedirect('http://localhost/product-category/photo-frames/wall-frames/');
        $this->get('/product-category/photo-frames/page/2/')->assertStatus(301);
        $this->get('/shop/')->assertOk();
        $this->get('/about-us')->assertStatus(301);
        $this->get('/about-us/')->assertOk();
        $this->get('/?product=magic-photo-mug')->assertRedirect('http://localhost/product/magic-photo-mug/');
    }

    public function test_saved_redirects_catch_old_urls(): void
    {
        Redirect::create(['from_path' => '/old-mug', 'to_path' => '/product/magic-photo-mug/']);
        $this->get('/old-mug/')->assertRedirect('/product/magic-photo-mug/')->assertStatus(301);
        $this->get('/really-missing/')->assertNotFound();
    }

    public function test_product_page_prints_seo_and_schema(): void
    {
        $html = $this->get('/product/personalised-wooden-photo-frame/')->assertOk()->getContent();
        $this->assertStringContainsString('<link rel="canonical" href="http://localhost/product/personalised-wooden-photo-frame/">', $html);
        $this->assertStringContainsString('"@type":"Product"', $html);
        $this->assertStringContainsString('"@type":"AggregateOffer"', $html);
        $this->assertStringContainsString('"@type":"BreadcrumbList"', $html);
        $this->assertStringContainsString('"@type":"FAQPage"', $html);
        $this->assertStringContainsString('"lowPrice":"399.00"', $html);
        $this->assertStringContainsString('property="product:price:amount" content="399.00"', $html);
        $this->assertStringContainsString('mfuTrack({"event":"view_item"', $html);
    }

    public function test_sitemaps_and_feeds(): void
    {
        $this->get('/sitemap.xml')->assertOk()->assertSee('sitemap-products.xml');
        $this->get('/sitemap-products.xml')->assertOk()->assertSee('http://localhost/product/magic-photo-mug/', false);
        $this->get('/sitemap-categories.xml')->assertOk()->assertSee('product-category/photo-frames/wall-frames/');
        $feed = $this->get('/feeds/google-merchant.xml')->assertOk()->getContent();
        $this->assertSame(4, substr_count($feed, '<g:item_group_id>MFU-FRAME</g:item_group_id>'));
        $this->assertStringContainsString('<g:sale_price>399.00 INR</g:sale_price>', $feed);
        $this->get('/feeds/facebook-catalog.csv')->assertOk();
    }

    public function test_photo_upload_is_required_and_add_to_cart_validates(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photoField = $mug->customizationFields->firstWhere('type', 'images');

        $this->postJson(route('cart.add', $mug), ['qty' => 1])->assertStatus(422)->assertJsonValidationErrors('custom.'.$photoField->id);

        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg', 1200, 1200)])->assertOk()->json('token');
        $this->postJson(route('cart.add', $mug), ['qty' => 2, 'custom' => [$photoField->id => [$token]]])->assertOk()->assertJson(['count' => 2]);
    }

    public function test_someone_elses_upload_token_is_not_accepted(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $field = $mug->customizationFields->firstWhere('type', 'images');
        $u = CustomerUpload::create(['token' => (string) Str::uuid(), 'session_id' => 'another-session', 'path' => 'x.jpg', 'original_name' => 'x.jpg', 'mime' => 'image/jpeg', 'size' => 1]);
        $this->postJson(route('cart.add', $mug), ['custom' => [$field->id => [$u->token]]])->assertStatus(422);
    }

    public function test_variable_product_needs_an_option_and_uses_variant_price(): void
    {
        $frame = Product::where('slug', 'personalised-wooden-photo-frame')->with('variants')->first();
        $field = $frame->customizationFields->firstWhere('type', 'images');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('a.jpg')])->json('token');

        $this->postJson(route('cart.add', $frame), ['custom' => [$field->id => [$token]]])->assertStatus(422)->assertJsonValidationErrors('variant_id');

        $big = $frame->variants->first(fn ($v) => $v->options['Size'] === '12x15');
        $this->postJson(route('cart.add', $frame), ['variant_id' => $big->id, 'custom' => [$field->id => [$token]]])->assertOk();
        $this->get('/cart')->assertOk()->assertSee('₹649');
    }

    public function test_cod_checkout_creates_order_and_links_photos(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $photo = $mug->customizationFields->firstWhere('type', 'images');
        $box = $mug->customizationFields->firstWhere('type', 'select');
        $token = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('me.jpg')])->json('token');
        $this->post(route('cart.add', $mug), ['custom' => [$photo->id => [$token], $box->id => 'Premium gift box']]);
        $this->post(route('cart.coupon'), ['code' => 'welcome10']);

        $res = $this->post(route('checkout.place'), [
            'name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '+91 98765 43210', 'address_line1' => '12 MG Road',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'payment_method' => 'cod',
        ]);
        $res->assertSessionHasNoErrors();
        $order = Order::with('items')->firstOrFail();
        $res->assertRedirect(route('checkout.thanks', $order->number));

        // 449 + 99 gift box = 548; 10% off = 54.80 -> 493.20, under the 499 free-shipping line so +60; + COD fee 50
        $this->assertEquals(548, (float) $order->subtotal);
        $this->assertEquals(54.80, (float) $order->discount);
        $this->assertEquals(60, (float) $order->shipping);
        $this->assertEquals(50, (float) $order->cod_fee);
        $this->assertEquals(603.20, (float) $order->total);
        $this->assertSame('9876543210', $order->phone);
        $this->assertSame('confirmed', $order->status);
        $this->assertSame($order->items[0]->id, CustomerUpload::where('token', $token)->value('order_item_id'));
        $this->assertSame(1, Coupon::where('code', 'WELCOME10')->value('used'));

        $this->get(route('checkout.thanks', $order->number))->assertOk()->assertSee($order->number)->assertSee('"event":"purchase"', false);
        $this->get('/cart')->assertSee('Your cart is empty');
    }

    public function test_razorpay_signature_is_verified(): void
    {
        config(['store.razorpay.key' => 'rzp_test_x', 'store.razorpay.secret' => 'shh', 'store.razorpay.webhook_secret' => 'hook']);
        $order = Order::create(['number' => 'MFU1', 'status' => 'pending', 'payment_method' => 'razorpay', 'razorpay_order_id' => 'order_abc',
            'name' => 'A', 'email' => 'a@b.c', 'phone' => '9876543210', 'address_line1' => 'x', 'city' => 'x', 'state' => 'Goa', 'pincode' => '403001',
            'subtotal' => 500, 'total' => 500]);

        $this->post(route('checkout.verify'), ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => 'forged'])
            ->assertRedirect(route('checkout'));
        $this->assertSame('failed', $order->fresh()->payment_status);

        $sig = hash_hmac('sha256', 'order_abc|pay_1', 'shh');
        $this->post(route('checkout.verify'), ['razorpay_order_id' => 'order_abc', 'razorpay_payment_id' => 'pay_1', 'razorpay_signature' => $sig])
            ->assertRedirect(route('checkout.thanks', 'MFU1'));
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('confirmed', $order->fresh()->status);

        // Webhook with a bad signature is refused
        $this->postJson(route('webhooks.razorpay'), ['event' => 'payment.captured'], ['X-Razorpay-Signature' => 'nope'])->assertStatus(400);
    }

    public function test_thank_you_page_is_private(): void
    {
        Order::create(['number' => 'MFU2', 'payment_method' => 'cod', 'name' => 'A', 'email' => 'a@b.c', 'phone' => '9876543210',
            'address_line1' => 'x', 'city' => 'x', 'state' => 'Goa', 'pincode' => '403001', 'subtotal' => 1, 'total' => 1]);
        $this->get(route('checkout.thanks', 'MFU2'))->assertNotFound();
        $this->get(route('track', ['number' => 'mfu2', 'phone' => '98765 43210']))->assertOk()->assertSee('MFU2');
    }

    public function test_search_and_suggest(): void
    {
        $this->get('/search?q=mug')->assertOk()->assertSee('Magic Photo Mug');
        $this->getJson('/search/suggest?q=fra')->assertOk()->assertJsonPath('products.0.url', fn ($u) => str_contains($u, '/product/'));
        $this->get('/?s=lamp')->assertRedirect(route('search', ['q' => 'lamp']));
    }

    public function test_category_path_follows_parent_rename(): void
    {
        $parent = Category::where('slug', 'photo-frames')->first();
        $parent->update(['slug' => 'frames']);
        $this->assertSame('frames/wall-frames', Category::where('slug', 'wall-frames')->value('path'));
    }

    public function test_admin_requires_admin(): void
    {
        $this->get('/admin')->assertRedirect('/login');
        $user = User::factory()->create();
        $this->actingAs($user)->get('/admin')->assertForbidden();
        $user->forceFill(['is_admin' => true])->save();
        $this->actingAs($user)->get('/admin')->assertOk();
    }
}
