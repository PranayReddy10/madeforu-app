<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/** One image in the admin media library: a file on the media disk or an image already hosted elsewhere (Spaces CDN). */
class Media extends Model
{
    protected $table = 'media';

    protected $guarded = ['id'];

    public function url(): string
    {
        return media_url($this->path);
    }

    public function isRemote(): bool
    {
        return (bool) preg_match('#^https?://#', $this->path);
    }

    public static function register(string $path, string $folder = 'general', ?string $alt = null): self
    {
        return static::firstOrCreate(['path' => $path], [
            'name' => basename((string) parse_url($path, PHP_URL_PATH)) ?: $path,
            'alt' => $alt,
            'folder' => $folder,
        ]);
    }

    /** Is this image used by a product, variation, category or banner? */
    public function inUse(): bool
    {
        $p = $this->path;

        return DB::table('product_images')->where('path', $p)->exists()
            || DB::table('product_variants')->where('image', $p)->exists()
            || DB::table('categories')->where('image', $p)->exists()
            || DB::table('banners')->where('image', $p)->orWhere('mobile_image', $p)->exists();
    }

    /** A path a form may point at: one in the library, or an image URL. */
    public static function acceptable(?string $path): bool
    {
        if ($path === null || $path === '') {
            return true;
        }

        return (bool) preg_match('#^https?://#', $path) || static::where('path', $path)->exists();
    }

    public function toPicker(): array
    {
        return ['id' => $this->id, 'path' => $this->path, 'url' => $this->url(), 'name' => $this->name, 'alt' => $this->alt,
            'width' => $this->width, 'height' => $this->height, 'folder' => $this->folder];
    }
}
