<?php

namespace App\Console\Commands;

use App\Models\Category;
use App\Models\Product;
use App\Services\SeoFill;
use Illuminate\Console\Command;

class SeoFillCommand extends Command
{
    protected $signature = 'store:seo-fill';

    protected $description = 'Fill missing or weak SEO fields (focus keyword, meta title, description, keywords, image alt) for all products and categories';

    public function handle(SeoFill $fill): int
    {
        [$p, $c] = self::fillAll($fill);
        $this->info("Updated {$p} products and {$c} categories.");

        return self::SUCCESS;
    }

    /** @return array{0: int, 1: int} products and categories changed */
    public static function fillAll(SeoFill $fill): array
    {
        $p = 0;
        Product::with('images', 'categories')->chunkById(100, function ($products) use ($fill, &$p) {
            foreach ($products as $product) {
                $p += $fill->product($product) ? 1 : 0;
            }
        });
        $c = Category::all()->sum(fn ($cat) => $fill->category($cat) ? 1 : 0);

        return [$p, $c];
    }
}
