<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // The admin media library: every product, category and banner image, picked from one place.
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('path', 1000);               // relative path on the media disk, or an absolute URL (Spaces CDN)
            $table->string('name');
            $table->string('alt')->nullable();
            $table->string('mime', 100)->nullable();
            $table->unsignedInteger('size')->nullable();
            $table->unsignedInteger('width')->nullable();
            $table->unsignedInteger('height')->nullable();
            $table->string('folder', 40)->default('general'); // products, categories, banners, general
            $table->timestamps();
            $table->index('folder');
        });
        if (in_array(DB::getDriverName(), ['mysql', 'mariadb'], true)) {
            Schema::table('media', fn (Blueprint $t) => $t->rawIndex('path(191)', 'media_path_index'));
        } else {
            Schema::table('media', fn (Blueprint $t) => $t->index('path'));
        }

        // Backfill from images already in use.
        $seen = [];
        $now = now();
        $rows = [];
        $add = function (?string $path, string $folder, ?string $alt = null) use (&$seen, &$rows, $now) {
            if (! $path || isset($seen[$path])) {
                return;
            }
            $seen[$path] = true;
            $rows[] = ['path' => $path, 'name' => basename((string) parse_url($path, PHP_URL_PATH)) ?: $path, 'alt' => $alt,
                'folder' => $folder, 'created_at' => $now, 'updated_at' => $now];
        };
        foreach (DB::table('product_images')->orderBy('id')->get(['path', 'alt']) as $r) {
            $add($r->path, 'products', $r->alt);
        }
        foreach (DB::table('product_variants')->whereNotNull('image')->pluck('image') as $p) {
            $add($p, 'products');
        }
        foreach (DB::table('categories')->whereNotNull('image')->pluck('image') as $p) {
            $add($p, 'categories');
        }
        foreach (DB::table('banners')->get(['image', 'mobile_image', 'title']) as $b) {
            $add($b->image, 'banners', $b->title);
            $add($b->mobile_image, 'banners', $b->title);
        }
        foreach (array_chunk($rows, 200) as $chunk) {
            DB::table('media')->insert($chunk);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('media');
    }
};
