<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class StorageFallbackTest extends TestCase
{
    public function test_public_files_load_without_the_storage_link(): void
    {
        Storage::disk('public')->put('products/test-fallback.txt', 'hello');

        $this->get('/storage/products/test-fallback.txt')->assertOk();
        $this->get('/storage/products/missing.jpg')->assertNotFound();
        $this->get('/storage/../.env')->assertNotFound();

        Storage::disk('public')->delete('products/test-fallback.txt');
    }
}
