<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\User;
use App\Services\ImportQueue;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AdminImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        File::delete([ImportQueue::stateFile(), ImportQueue::logFile()]);
    }

    protected function tearDown(): void
    {
        File::delete([ImportQueue::stateFile(), ImportQueue::logFile()]);
        parent::tearDown();
    }

    private function fakeShop(): void
    {
        $site = 'https://shop.test';
        Http::fake([
            "$site/wp-json/wc/v3/products/categories*" => Http::response([['id' => 10, 'name' => 'Mugs', 'slug' => 'mugs', 'parent' => 0, 'description' => '', 'image' => null, 'count' => 1]]),
            "$site/wp-json/wc/v3/products/reviews*" => Http::response([]),
            "$site/wp-json/wc/v3/products*" => Http::response([
                ['id' => 5, 'name' => 'Photo Mug', 'slug' => 'photo-mug', 'status' => 'publish', 'type' => 'simple', 'sku' => 'MUG', 'permalink' => "$site/product/photo-mug/",
                    'price' => '249', 'regular_price' => '299', 'sale_price' => '249', 'stock_status' => 'instock', 'images' => [], 'categories' => [['id' => 10]], 'tags' => [], 'attributes' => []],
            ]),
            "$site/wp-json/*" => Http::response([]),
        ]);
    }

    public function test_admin_queues_an_import_and_runs_it_in_the_browser(): void
    {
        $this->fakeShop();
        $admin = User::factory()->create();
        $admin->forceFill(['is_admin' => true])->save();
        $this->actingAs($admin);

        $this->get('/admin/products/')->assertOk()->assertSee('Import them from WordPress');
        $this->get('/admin/import/')->assertOk()->assertSee('Start import');

        $this->post('/admin/import/', ['url' => 'https://shop.test', 'key' => 'ck_abc', 'secret' => 'cs_xyz', 'customers' => '0', 'images' => '0'])
            ->assertRedirect();
        $this->assertStringNotContainsString('cs_xyz', File::get(ImportQueue::stateFile()));   // keys are stored encrypted
        $this->get('/admin/import/')->assertSee('Waiting to start')->assertSee('Run now');
        $this->post('/admin/import/', ['url' => 'https://shop.test'])->assertSessionHas('error');  // one at a time

        $this->post('/admin/import/run/')->assertRedirect()->assertSessionHas('status', 'Import finished.');

        $this->assertSame(1, Product::count());
        $state = ImportQueue::state();
        $this->assertSame('done', $state['status']);
        $this->assertFalse($state['has_keys']);                                                 // deleted after the run
        $this->get('/admin/import/')->assertSee('Finished')->assertSee('1 products');
    }

    public function test_scheduler_runs_a_queued_import_and_reports_failures(): void
    {
        Http::fake(['*' => Http::response('blocked', 403)]);
        ImportQueue::queue('https://shop.test', null, null, false, false);

        $this->artisan('store:import-queued')->assertSuccessful();

        $state = ImportQueue::state();
        $this->assertSame('failed', $state['status']);
        $this->assertStringContainsString('HTTP 403', $state['error']);
        $this->assertFalse(ImportQueue::busy());
        $this->assertFalse(ImportQueue::runQueued());   // nothing waiting any more
    }
}
