<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Support\Personalisation;
use Database\Seeders\DemoSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use ZipArchive;

class PersonalisationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoSeeder::class);
        Storage::fake('local');
        Mail::fake();
        $this->useBrowserSession();
        $this->admin = User::factory()->create();
        $this->admin->forceFill(['is_admin' => true])->save();
    }

    public function test_bulk_apply_preset_to_a_category_and_copy_from_a_product(): void
    {
        $mugs = Category::where('slug', 'custom-mugs')->first();
        $this->actingAs($this->admin)->post(route('admin.products.personalisation'), ['source' => 'couple', 'category' => $mugs->id])
            ->assertSessionHas('status');
        $mug = Product::where('slug', 'magic-photo-mug')->with('customizationFields')->first();
        $this->assertSame(['images', 'text'], $mug->customizationFields->pluck('type')->all());
        $photos = $mug->customizationFields->first();
        $this->assertSame([2, 2], [$photos->min_files, $photos->max_files]);
        $this->assertSame(['His photo', 'Her photo'], collect($photos->options)->pluck('label')->all());

        $lamp = Product::where('slug', '3d-moon-photo-lamp')->first();
        $this->actingAs($this->admin)->post(route('admin.products.personalisation'), ['source' => 'product:'.$mug->id, 'ids' => [$lamp->id]]);
        $this->assertSame(['images', 'text'], $lamp->customizationFields()->pluck('type')->all());

        $this->actingAs($this->admin)->get(route('admin.products.index'))->assertSee('📷 2 photos + Names');
    }

    public function test_slot_labels_flow_from_product_page_to_order_and_downloads(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        Personalisation::apply($mug, Personalisation::presets()['couple'][1]);
        $mug->load('customizationFields');
        $this->get($mug->url())->assertSee('His photo')->assertSee('Her photo');

        [$photos, $names] = [$mug->customizationFields[0], $mug->customizationFields[1]];
        $t1 = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('him.jpg', 1600, 1600)])->json('token');
        $t2 = $this->postJson(route('upload.store'), ['file' => UploadedFile::fake()->image('her.jpg', 500, 500)])->json('token');
        $this->postJson(route('cart.add', $mug), ['custom' => [$photos->id => [$t1, $t2], $names->id => 'Ravi & Priya']])->assertOk();
        $this->post(route('checkout.place'), ['name' => 'Asha Rao', 'email' => 'a@example.com', 'phone' => '9876543210', 'address_line1' => 'x',
            'city' => 'Hyderabad', 'state' => 'Telangana', 'pincode' => '500001', 'payment_method' => 'cod'])->assertRedirect();
        $order = Order::with('items.uploads')->firstOrFail();
        $this->assertSame(['His photo', 'Her photo'], $order->items[0]->customization[0]['slots']);

        $page = $this->actingAs($this->admin)->get(route('admin.orders.show', $order))->assertOk();
        $page->assertSee('His photo')->assertSee('Her photo')->assertSee('Ravi &amp; Priya', false)->assertSee('low res')->assertSee('Production sheet');

        $her = $order->items[0]->uploads->firstWhere('token', $t2);
        $dl = $this->actingAs($this->admin)->get(route('admin.orders.upload', [$order, $her]))->assertOk();
        $this->assertStringContainsString($order->number.'-item1-her-photo.jpg', $dl->headers->get('Content-Disposition'));

        $this->actingAs($this->admin)->get(route('admin.orders.production', $order))->assertOk()
            ->assertSee('Ship by')->assertSee('Ravi &amp; Priya', false)->assertSee('Low resolution');

        $zipRes = $this->actingAs($this->admin)->get(route('admin.orders.uploads', $order))->assertOk();
        $zip = new ZipArchive;
        $zip->open($zipRes->baseResponse->getFile()->getPathname());
        $names = collect(range(0, $zip->numFiles - 1))->map(fn ($i) => $zip->getNameIndex($i))->all();
        $this->assertContains('order-details.txt', $names);
        $this->assertContains('item1-magic-photo-mug/'.$order->number.'-item1-his-photo.jpg', $names);
        $this->assertStringContainsString('Names: Ravi & Priya', $zip->getFromName('order-details.txt'));
    }

    public function test_product_form_saves_photo_labels(): void
    {
        $mug = Product::where('slug', 'magic-photo-mug')->first();
        $this->actingAs($this->admin)->get(route('admin.products.edit', $mug))->assertOk()->assertSee('Quick setup')->assertSee('Couple (his &amp; her photo + names)', false);

        $this->actingAs($this->admin)->put(route('admin.products.update', $mug), [
            'name' => $mug->name, 'slug' => $mug->slug, 'type' => 'simple', 'price' => 449,
            'fields' => [
                ['type' => 'images', 'label' => 'Upload photos', 'min_files' => 2, 'max_files' => 2, 'slots' => "Front side\nBack side\nExtra", 'required' => '1'],
                ['type' => 'text', 'label' => 'Name to print', 'max_length' => 20, 'required' => '1'],
            ],
        ])->assertSessionHasNoErrors();
        $f = $mug->fresh()->customizationFields()->orderBy('position')->get();
        $this->assertSame(['Front side', 'Back side'], collect($f[0]->options)->pluck('label')->all());   // trimmed to 2 photos
        $this->assertSame('Name to print', $f[1]->label);
        $this->actingAs($this->admin)->get(route('admin.products.edit', $mug))->assertSee("Front side\nBack side", false);
    }
}
