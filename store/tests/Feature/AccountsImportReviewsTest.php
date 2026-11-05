<?php

namespace Tests\Feature;

use App\Http\Controllers\ReviewRequestController;
use App\Mail\ReviewRequest;
use App\Models\CustomerUpload;
use App\Models\Order;
use App\Models\Product;
use App\Models\Review;
use App\Models\Setting;
use App\Models\User;
use App\Services\WooImporter;
use Database\Seeders\DemoSeeder;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AccountsImportReviewsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Storage::fake('public');
        Mail::fake();
        $this->useBrowserSession();
    }

    public function test_forgot_password_by_email_or_phone_and_reset(): void
    {
        Notification::fake();
        $user = User::create(['name' => 'Asha Rao', 'email' => 'asha@example.com', 'phone' => '9876543210', 'password' => 'old-password']);
        $this->get('/login')->assertSee('Forgot password?');

        $this->post(route('password.email'), ['login' => '98765 43210'])->assertSessionHas('status');
        $token = null;
        Notification::assertSentTo($user, ResetPassword::class, function ($n) use (&$token) {
            $token = $n->token;

            return true;
        });
        // Unknown address gets the same answer (no account fishing)
        $this->post(route('password.email'), ['login' => 'nobody@example.com'])->assertSessionHas('status');

        $this->get(route('password.reset', ['token' => $token, 'email' => 'asha@example.com']))->assertOk()->assertSee('Set a new password');
        $this->post(route('password.update'), ['token' => $token, 'email' => 'asha@example.com', 'password' => 'new-password-1', 'password_confirmation' => 'new-password-1'])
            ->assertRedirect(route('account'));
        $this->assertAuthenticatedAs($user);
        $this->post(route('logout'));
        $this->post('/login', ['login' => 'asha@example.com', 'password' => 'new-password-1'])->assertRedirect();
        $this->assertAuthenticated();
    }

    public function test_imports_customers_and_orders_with_their_photos(): void
    {
        $site = 'https://shop.test';
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $mug->forceFill(['woo_id' => 500])->save();
        Http::fake([
            "$site/wp-json/wc/v3/customers*" => Http::response([
                ['id' => 7, 'email' => 'Meera@Example.com', 'first_name' => 'Meera', 'last_name' => 'K', 'role' => 'customer', 'billing' => ['phone' => '+91 99887 76655'], 'date_created' => '2026-05-01T10:00:00'],
                ['id' => 1, 'email' => 'owner@madeforu.co.in', 'role' => 'administrator', 'billing' => []],
            ]),
            "$site/wp-json/wc/v3/orders*" => Http::response([[
                'id' => 9001, 'number' => '1234', 'status' => 'completed', 'customer_id' => 7, 'payment_method' => 'cod', 'date_paid' => null,
                'date_created' => '2026-06-01T12:00:00', 'date_completed' => '2026-06-05T12:00:00', 'customer_note' => 'Gift wrap please',
                'billing' => ['first_name' => 'Meera', 'last_name' => 'K', 'email' => 'meera@example.com', 'phone' => '9988776655'],
                'shipping' => ['first_name' => 'Meera', 'last_name' => 'K', 'address_1' => '1 Lake Rd', 'city' => 'Hyderabad', 'state' => 'TS', 'postcode' => '500 081'],
                'discount_total' => '0', 'shipping_total' => '60', 'total' => '509.00', 'fee_lines' => [], 'coupon_lines' => [],
                'line_items' => [[
                    'product_id' => 500, 'variation_id' => 0, 'name' => 'Magic Photo Mug', 'quantity' => 1, 'subtotal' => '449', 'total' => '449', 'sku' => 'MUG',
                    'meta_data' => [
                        ['key' => 'Upload Image', 'display_key' => 'Upload Image', 'value' => 'pic.jpg', 'display_value' => 'pic.jpg'],
                        ['key' => 'Name to print', 'display_key' => 'Name to print', 'value' => 'Meera', 'display_value' => 'Meera'],
                        ['key' => '_mfu_cb_data', 'value' => [['item' => 1, 'fields' => [['key' => 'uploadimage', 'label' => 'Upload Image', 'type' => 'photo',
                            'instances' => [['photo_url' => "$site/wp-content/uploads/mfu-custom/pic.jpg", 'photo_name' => 'pic.jpg']]]]]]],
                    ],
                ]],
            ]]),
            "$site/wp-content/*" => Http::response('jpegbytes', 200, ['Content-Type' => 'image/jpeg']),
        ]);

        $importer = new WooImporter($site, 'ck', 'cs');
        $importer->importCustomers();
        $importer->importOrders();
        $importer->importOrders();   // running twice changes nothing

        $user = User::where('woo_id', 7)->first();
        $this->assertSame('meera@example.com', $user->email);
        $this->assertSame('9988776655', $user->phone);
        $this->assertNull(User::where('email', 'owner@madeforu.co.in')->first());

        $order = Order::where('woo_id', 9001)->with('items')->first();
        $this->assertSame(['WC-1234', 'delivered', 'paid', 'Telangana', '500081'], [$order->number, $order->status, $order->payment_status, $order->state, $order->pincode]);
        $this->assertSame($user->id, $order->user_id);
        $this->assertCount(1, Order::all());
        $this->assertSame($mug->id, $order->items[0]->product_id);
        $custom = collect($order->items[0]->customization);
        $this->assertSame('images', $custom[0]['type']);
        $this->assertSame('Meera', $custom->firstWhere('label', 'Name to print')['value']);
        $this->assertNull($custom->where('label', 'Upload Image')->where('type', 'text')->first());   // the file name is replaced by the photo itself
        $this->assertSame(1, CustomerUpload::where('order_item_id', $order->items[0]->id)->count());
        Mail::assertNothingSent();

        // The customer sets a password and sees the old order
        $user->forceFill(['password' => 'secret-123'])->save();
        $this->actingAs($user)->get('/my-account')->assertSee('WC-1234');
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin)->get(route('admin.orders.show', $order))->assertOk()->assertSee('Gift wrap please');
    }

    public function test_review_with_photos_and_verified_badge(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $user = User::create(['name' => 'Asha', 'email' => 'asha@example.com', 'password' => 'x-password']);
        $order = Order::create(['number' => 'MFU5', 'status' => 'delivered', 'payment_method' => 'cod', 'user_id' => $user->id, 'name' => 'Asha', 'email' => 'asha@example.com',
            'phone' => '9876543210', 'address_line1' => 'x', 'city' => 'x', 'state' => 'Goa', 'pincode' => '403001', 'subtotal' => 449, 'total' => 449]);
        $order->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        $this->actingAs($user)->post(route('product.review', $mug), ['name' => 'Asha', 'rating' => 5, 'body' => 'Lovely!',
            'photos' => [UploadedFile::fake()->image('gift.jpg', 2000, 1500)]])->assertSessionHas('status');
        $r = Review::latest('id')->first();
        $this->assertTrue($r->is_verified);
        $this->assertFalse($r->is_approved);
        Storage::disk('public')->assertExists($r->photos[0]);

        $r->update(['is_approved' => true]);
        $this->get($mug->url())->assertSee('Verified buyer')->assertSee('Customer photo of', false);

        // Bots that fill the hidden field are refused
        $this->post(route('product.review', $mug), ['name' => 'Bot', 'rating' => 5, 'website' => 'spam.example'])->assertSessionHasErrors('website');
    }

    public function test_review_request_after_delivery_and_review_page(): void
    {
        Setting::put(['reviews.request_days' => '2', 'reviews.auto_approve' => '1']);
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $order = Order::create(['number' => 'MFU6', 'status' => 'delivered', 'payment_method' => 'cod', 'name' => 'Ravi Kumar', 'email' => 'ravi@example.com',
            'phone' => '9876543210', 'address_line1' => 'x', 'city' => 'x', 'state' => 'Goa', 'pincode' => '403001', 'subtotal' => 449, 'total' => 449, 'delivered_at' => now()->subDay()]);
        $order->items()->create(['product_id' => $mug->id, 'name' => $mug->name, 'qty' => 1, 'unit_price' => 449, 'line_total' => 449]);

        $this->artisan('store:review-requests');
        Mail::assertNothingSent();                       // only 1 day since delivery
        $this->travel(2)->days();
        $this->artisan('store:review-requests');
        Mail::assertSent(ReviewRequest::class, fn ($m) => $m->hasTo('ravi@example.com'));
        $this->artisan('store:review-requests');
        Mail::assertSent(ReviewRequest::class, 1);       // once only

        $link = ReviewRequestController::link($order);
        $this->get(route('review.order', 'MFU6'))->assertForbidden();
        $this->get($link)->assertOk()->assertSee('How was your gift?')->assertSee($mug->name);
        $this->post($link, ['name' => 'Ravi', 'reviews' => [$mug->id => ['rating' => 5, 'body' => 'Perfect']],
            'photos' => [$mug->id => [UploadedFile::fake()->image('a.jpg')]]])->assertRedirect(route('home'));
        $r = Review::where('order_id', $order->id)->first();
        $this->assertTrue($r->is_verified && $r->is_approved);
        $this->assertCount(1, $r->photos);
        $this->get($link)->assertSee('already reviewed everything');
    }
}
