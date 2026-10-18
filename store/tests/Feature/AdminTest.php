<?php

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\Redirect;
use App\Models\Review;
use App\Models\SearchQuery;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_pages(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create(['is_admin' => true]);
        $cat = Category::create(['name' => 'Frames', 'slug' => 'frames']);
        $child = Category::create(['name' => 'Wood', 'slug' => 'wood', 'parent_id' => $cat->id]);
        $this->actingAs($admin);

        // create product with everything
        $r = $this->post(route('admin.products.store'), [
            'name' => 'Photo Frame A4', 'type' => 'variable', 'price' => 499, 'sale_price' => 399,
            'categories' => [$child->id], 'is_active' => 1, 'in_stock' => 1,
            'meta_title' => 'Personalised photo frame A4 buy online India', 'focus_keyword' => 'photo frame',
            'faqs' => [['q' => 'How long?', 'a' => '3 days'], ['q' => '', 'a' => '']],
            'attrs' => [['name' => 'Size', 'values' => 'A4, A3']],
            'variants' => [['options' => 'Size=A4', 'price' => 499, 'in_stock' => 1], ['options' => 'Size=A3', 'price' => 699]],
            'fields' => [['type' => 'images', 'label' => 'Upload 4 photos', 'min_files' => 4, 'max_files' => 4, 'required' => 1],
                ['type' => 'select', 'label' => 'Wrap', 'options' => "Gift wrap|49\nNone|0"]],
            'new_images' => [UploadedFile::fake()->image('a.jpg', 900, 900)],
        ]);
        $r->assertSessionHasNoErrors();
        $p = Product::first();
        $this->assertSame('photo-frame-a4', $p->slug);
        $this->assertCount(2, $p->variants);
        $this->assertFalse($p->variants->firstWhere('price', 699)->in_stock);
        $this->assertCount(2, $p->customizationFields);
        $this->assertEquals(49, $p->customizationFields[1]->options[0]['price']);
        $this->assertCount(1, $p->faqs);
        $this->assertCount(1, $p->images);
        $this->assertSame($child->id, $p->primary_category_id);

        // update: drop A3 variant, keep field ids
        $fid = $p->customizationFields[0]->id;
        $v = $p->variants->firstWhere('price', 499);
        $this->put(route('admin.products.update', $p), [
            'name' => 'Photo Frame A4', 'slug' => 'photo-frame-a4', 'type' => 'variable', 'price' => 499,
            'variants' => [['id' => $v->id, 'options' => 'Size=A4', 'price' => 459, 'in_stock' => 1]],
            'fields' => [['id' => $fid, 'type' => 'images', 'label' => 'Upload 4 photos', 'min_files' => 4, 'max_files' => 4, 'required' => 1]],
            'images' => [$p->images[0]->id => ['alt' => 'Frame front', 'position' => 0]],
        ])->assertSessionHasNoErrors();
        $p->refresh();
        $this->assertCount(1, $p->variants);
        $this->assertSame($v->id, $p->variants[0]->id);
        $this->assertSame($fid, $p->customizationFields[0]->id);
        $this->assertSame('Frame front', $p->images[0]->alt);

        $order = Order::create(['number' => 'MFU1', 'payment_method' => 'cod', 'name' => 'A', 'email' => 'a@b.c', 'phone' => '9876543210',
            'address_line1' => 'x', 'city' => 'Hyd', 'state' => 'Telangana', 'pincode' => '500001', 'subtotal' => 459, 'total' => 459, 'status' => 'confirmed',
            'utm' => ['utm_source' => 'google']]);
        $order->items()->create(['product_id' => $p->id, 'name' => $p->name, 'qty' => 1, 'unit_price' => 459, 'line_total' => 459,
            'options' => ['Size' => 'A4'], 'customization' => [['label' => 'Upload 4 photos', 'type' => 'images', 'value' => null, 'files' => []], ['label' => 'Msg', 'type' => 'text', 'value' => 'Hi']]]);
        SearchQuery::create(['term' => 'mug', 'results' => 0]);
        Review::create(['product_id' => $p->id, 'name' => 'R', 'rating' => 5, 'body' => 'Nice']);

        foreach ([route('admin.dashboard'), route('admin.products.index'), route('admin.products.index', ['q' => 'frame', 'category' => $child->id, 'active' => '1']),
            route('admin.products.create'), route('admin.products.edit', $p), route('admin.categories.index'), route('admin.categories.create'),
            route('admin.categories.edit', $cat), route('admin.orders.index', ['status' => 'confirmed', 'q' => '98']), route('admin.orders.show', $order),
            route('admin.coupons.index'), route('admin.coupons.create'), route('admin.pages.index'), route('admin.pages.create'),
            route('admin.banners.index'), route('admin.banners.create'), route('admin.redirects.index'), route('admin.redirects.create'),
            route('admin.reviews.index'), route('admin.seo'), route('admin.settings'), route('admin.media.index')] as $url) {
            $res = $this->get($url);
            $this->assertSame(200, $res->status(), $url."\n".substr($res->getContent(), 0, 2000));
        }

        $this->put(route('admin.orders.update', $order), ['status' => 'shipped', 'payment_status' => 'paid', 'tracking_number' => 'AWB1'])->assertSessionHasNoErrors();
        $this->assertSame('shipped', $order->fresh()->status);
        $this->get(route('admin.orders.uploads', $order))->assertRedirect();

        // categories
        $this->put(route('admin.categories.update', $child), ['name' => 'Wood', 'slug' => 'wood', 'parent_id' => $child->id])->assertSessionHasErrors('parent_id');
        $this->put(route('admin.categories.update', $cat), ['name' => 'Frames', 'slug' => 'photo-frames', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame('photo-frames/wood', $child->fresh()->path);

        $this->post(route('admin.coupons.store'), ['code' => 'save10', 'type' => 'percent', 'value' => 10, 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.coupons.store'), ['code' => 'bad', 'type' => 'percent', 'value' => 150])->assertSessionHasErrors('value');
        $this->post(route('admin.pages.store'), ['title' => 'About us', 'body' => '<p>x</p>', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->post(route('admin.pages.store'), ['title' => 'Cart'])->assertSessionHasErrors('slug');
        $this->post(route('admin.redirects.store'), ['from_path' => 'https://madeforu.co.in/Old-Thing/', 'to_path' => '/product/photo-frame-a4/', 'status' => 301])->assertSessionHasNoErrors();
        $this->assertSame('/old-thing', Redirect::first()->from_path);
        $this->post(route('admin.banners.store'), ['title' => 'Hi', 'image' => UploadedFile::fake()->image('b.jpg'), 'is_active' => 1])->assertSessionHasNoErrors();
        $rv = Review::first();
        $this->put(route('admin.reviews.update', $rv), ['is_approved' => 1]);
        $this->assertSame(5.0, (float) $p->fresh()->rating_avg);
        $this->put('/admin/settings', ['analytics__ga4' => 'G-TEST', 'cod_enabled' => '0', 'shipping_fee' => '70'])->assertSessionHasNoErrors();
        $this->assertSame('G-TEST', setting('analytics.ga4'));
        $this->assertSame('0', setting('cod_enabled'));
        $this->post(route('admin.products.images', $p), ['images' => [UploadedFile::fake()->image('c.png')]])->assertSessionHasNoErrors();
        $this->delete(route('admin.products.images.delete', $p->images()->first()))->assertRedirect();
        $this->delete(route('admin.products.destroy', $p))->assertRedirect(route('admin.products.index'));

        // non-admin blocked
        $this->actingAs(User::factory()->create())->get(route('admin.dashboard'))->assertForbidden();
    }
}
