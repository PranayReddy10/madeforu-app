<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Media;
use App\Models\ProductImage;
use App\Services\ImageTools;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;

/**
 * Makes the shop faster on shared hosting:
 *  1. photos are served by the web server directly (public/storage), not through PHP one by one;
 *  2. every catalogue photo gets WebP copies, including a small one for product cards;
 *  3. Blade views are pre-compiled.
 * Safe to run again at any time (Admin → Dashboard → Speed up).
 */
class SpeedUp extends Command
{
    protected $signature = 'store:speed {--images-only : Only make the missing WebP copies} {--limit=0 : Stop after making this many copies (for running from the browser)}';

    protected $description = 'Serve photos directly, make small WebP copies for cards, pre-compile views';

    public function handle(): int
    {
        if (! $this->option('images-only')) {
            $this->line(self::publicFolder());
        }
        $made = 0;
        $disk = Storage::disk('public');
        $paths = ProductImage::pluck('path')->merge(Category::whereNotNull('image')->pluck('image'))->merge(Media::pluck('path'))
            ->filter(fn ($p) => $p && ! preg_match('#^https?://#', $p))->unique();
        $limit = (int) $this->option('limit');
        foreach ($paths as $p) {
            if ($limit && $made >= $limit) {
                $this->line("Made {$made} WebP copies — press Speed up again to continue.");
                break;
            }
            $made += ImageTools::variants($disk->path($p));
        }
        $this->line("Photos: {$paths->count()} checked, {$made} WebP copies made.");
        if (! $this->option('images-only')) {
            Artisan::call('view:cache');
            $this->line('Views pre-compiled.');
        }

        return self::SUCCESS;
    }

    /** What still needs doing, for the dashboard: photos through PHP, photos without a small copy. */
    public static function status(): array
    {
        $disk = Storage::disk('public');
        $missing = ProductImage::where('path', 'not like', 'http%')->pluck('path')->unique()
            ->filter(fn ($p) => $disk->exists($p) && preg_match('/\.(jpe?g|png|webp)$/i', $p) && ! $disk->exists(ImageTools::thumbPath($p)))->count();

        return ['direct' => is_link(public_path('storage')) || is_dir(public_path('storage')), 'missing' => $missing];
    }

    /** public/storage: a link if the host allows it, otherwise the uploads moved into it. */
    public static function publicFolder(): string
    {
        $link = public_path('storage');
        $target = storage_path('app/public');
        if (is_link($link) || is_dir($link)) {
            return 'Photos are already served directly from public/storage.';
        }
        if (function_exists('symlink') && @symlink($target, $link)) {
            return 'Linked public/storage to the uploads folder.';
        }
        if (is_dir($target) && @rename($target, $link)) {
            @mkdir($target, 0775, true);
            Artisan::call('config:clear');   // the "public" disk now points at public/storage

            return 'Moved the uploads into public/storage — the web server now sends photos itself.';
        }

        return 'Could not create public/storage: photos keep being served through the app (slower).';
    }
}
