<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Console\Commands\SeoFillCommand;
use App\Services\SeoFill;
use Illuminate\Support\Str;

class SeoController extends Controller
{
    /** @return array{score: int, issues: string[]} */
    public static function score(Product $p): array
    {
        $issues = [];
        $title = $p->meta_title ?: '';
        $desc = $p->meta_description ?: '';
        $kw = mb_strtolower(trim((string) $p->focus_keyword));
        $images = $p->relationLoaded('images') ? $p->images : $p->images()->get();

        $tl = mb_strlen($title);
        if ($tl < 30 || $tl > 60) {
            $issues[] = $tl ? "Meta title is {$tl} characters (aim for 30–60)." : 'No meta title set.';
        }
        $dl = mb_strlen($desc);
        if ($dl < 70 || $dl > 160) {
            $issues[] = $dl ? "Meta description is {$dl} characters (aim for 70–160)." : 'No meta description set.';
        }
        if ($kw === '') {
            $issues[] = 'No focus keyword.';
        } else {
            if (! str_contains(mb_strtolower($title ?: $p->name), $kw)) {
                $issues[] = 'Focus keyword is not in the title.';
            }
            if (! str_contains(mb_strtolower($desc), $kw)) {
                $issues[] = 'Focus keyword is not in the meta description.';
            }
            $slugKw = str_replace(' ', '-', $kw);
            if (! str_contains($p->slug, Str::slug($slugKw))) {
                $issues[] = 'Focus keyword is not in the URL slug.';
            }
        }
        if ($images->count() < 3) {
            $issues[] = 'Fewer than 3 images ('.$images->count().').';
        }
        if ($images->contains(fn ($i) => trim((string) $i->alt) === '')) {
            $issues[] = 'Some images have no alt text.';
        }
        if (mb_strlen(strip_tags((string) $p->description)) < 300) {
            $issues[] = 'Description is shorter than 300 characters.';
        }

        $checks = 8;
        $score = (int) round(100 * max(0, $checks - count($issues)) / $checks);

        return ['score' => $score, 'issues' => $issues];
    }

    /** One click: fill every missing or weak SEO field; good hand-written ones are kept. */
    public function fill(SeoFill $fill)
    {
        @set_time_limit(300);
        [$p, $c] = SeoFillCommand::fillAll($fill);

        return back()->with('status', "SEO filled in: {$p} products and {$c} categories updated. Fields that were already good were left as they were.");
    }

    public function index()
    {
        $products = Product::with('images')->get()
            ->map(fn ($p) => ['product' => $p] + self::score($p))
            ->sortBy('score')->values();
        $categories = Category::where(fn ($q) => $q->whereNull('meta_description')->orWhere('meta_description', ''))->orderBy('name')->get();

        return view('admin.seo', [
            'products' => $products,
            'average' => $products->count() ? (int) round($products->avg('score')) : 0,
            'categories' => $categories,
        ]);
    }
}
