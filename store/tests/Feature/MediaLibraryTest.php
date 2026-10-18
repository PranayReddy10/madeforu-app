<?php

namespace Tests\Feature;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Media;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class MediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        $this->actingAs(User::factory()->create(['is_admin' => true]));
    }

    private function upload(int $n = 2): array
    {
        $files = [];
        for ($i = 1; $i <= $n; $i++) {
            $files[] = UploadedFile::fake()->image("Photo {$i}.jpg", 800, 600);
        }

        return $this->postJson(route('admin.media.store'), ['files' => $files, 'folder' => 'products'])
            ->assertOk()->json('data');
    }

    public function test_upload_stores_files_and_lists_them(): void
    {
        $data = $this->upload(2);
        $this->assertCount(2, $data);
        $this->assertSame(800, $data[0]['width']);
        $this->assertStringStartsWith('media/'.now()->format('Y/m').'/photo-', $data[0]['path']);
        Storage::disk('public')->assertExists($data[0]['path']);
        $this->assertSame(2, Media::where('folder', 'products')->count());

        $list = $this->getJson(route('admin.media.index'))->assertOk();
        $this->assertCount(2, $list->json('data'));
        $this->assertNull($list->json('next_page_url'));
        $this->getJson(route('admin.media.index', ['q' => 'nothing-like-this']))->assertJsonCount(0, 'data');
        $this->get(route('admin.media.index'))->assertOk()->assertSee('Media library');

        $this->postJson(route('admin.media.store'), ['files' => [UploadedFile::fake()->create('x.pdf', 10, 'application/pdf')]])
            ->assertStatus(422);
    }

    public function test_picking_images_into_a_product_gallery(): void
    {
        [$a, $b] = $this->upload(2);
        $cdn = Media::register('https://friendsmedia.sgp1.cdn.digitaloceanspaces.com/wp-content/uploads/mug.png', 'products');
        $product = Product::create(['name' => 'Mug', 'slug' => 'mug', 'price' => 100]);
        $base = ['name' => 'Mug', 'slug' => 'mug', 'type' => 'simple', 'price' => 100, 'gallery_present' => 1];

        $this->put(route('admin.products.update', $product), $base + ['gallery' => [
            ['path' => $b['path'], 'alt' => 'Back'],
            ['path' => $a['path'], 'alt' => 'Front'],
            ['path' => $cdn->path, 'alt' => ''],
        ]])->assertSessionHasNoErrors();
        $imgs = $product->images()->get();
        $this->assertSame([$b['path'], $a['path'], $cdn->path], $imgs->pluck('path')->all());
        $this->assertSame('Back', $imgs[0]->alt);
        $keepId = $imgs[1]->id;

        // Reorder and remove one: the kept row is updated, not recreated
        $this->put(route('admin.products.update', $product), $base + ['gallery' => [['path' => $a['path'], 'alt' => 'Front side']]])
            ->assertSessionHasNoErrors();
        $imgs = $product->images()->get();
        $this->assertCount(1, $imgs);
        $this->assertSame($keepId, $imgs[0]->id);
        $this->assertSame(0, $imgs[0]->position);
        $this->assertSame('Front side', $imgs[0]->alt);

        // Paths that are not in the library are refused
        $this->put(route('admin.products.update', $product), $base + ['gallery' => [['path' => '../../.env']]])
            ->assertSessionHasErrors('gallery.0.path');

        // Every image removed
        $this->put(route('admin.products.update', $product), $base)->assertSessionHasNoErrors();
        $this->assertSame(0, $product->images()->count());
    }

    public function test_category_and_banner_take_a_library_image(): void
    {
        [$a] = $this->upload(1);
        $this->post(route('admin.categories.store'), ['name' => 'Mugs', 'image' => $a['path'], 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame($a['path'], Category::first()->image);
        $cat = Category::first();
        $this->put(route('admin.categories.update', $cat), ['name' => 'Mugs', 'slug' => 'mugs', 'image' => ''])->assertSessionHasNoErrors();
        $this->assertNull($cat->fresh()->image);

        $this->post(route('admin.banners.store'), ['title' => 'Sale', 'image' => $a['path'], 'mobile_image' => '', 'is_active' => 1])->assertSessionHasNoErrors();
        $this->assertSame($a['path'], Banner::first()->image);
        $this->post(route('admin.banners.store'), ['title' => 'No image', 'image' => ''])->assertSessionHasErrors('image');
    }

    public function test_an_image_in_use_cannot_be_deleted(): void
    {
        [$a, $b] = $this->upload(2);
        $product = Product::create(['name' => 'Mug', 'slug' => 'mug', 'price' => 100]);
        $product->images()->create(['path' => $a['path'], 'position' => 0]);

        $this->deleteJson(route('admin.media.destroy', $a['id']))->assertStatus(422);
        $this->assertDatabaseHas('media', ['id' => $a['id']]);
        Storage::disk('public')->assertExists($a['path']);

        $this->deleteJson(route('admin.media.destroy', $b['id']))->assertOk();
        $this->assertDatabaseMissing('media', ['id' => $b['id']]);
        Storage::disk('public')->assertMissing($b['path']);

        $this->putJson(route('admin.media.update', $a['id']), ['alt' => 'A mug'])->assertOk();
        $this->assertSame('A mug', Media::find($a['id'])->alt);
    }
}
