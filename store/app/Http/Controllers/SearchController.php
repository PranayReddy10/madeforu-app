<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\SearchQuery;
use App\Services\Analytics;
use App\Services\Seo;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function index(Request $request, Seo $seo, Analytics $analytics)
    {
        $term = str((string) $request->query('q', $request->query('s', '')))->squish()->limit(80, '')->toString();
        $products = $term === ''
            ? Product::query()->whereRaw('1 = 0')->paginate(24)
            : Product::active()->search($term)->with(['images', 'variants', 'primaryCategory', 'customizationFields'])
                ->orderByDesc('sales_count')->paginate(24)->withQueryString();
        if ($term !== '' && $products->currentPage() === 1) {
            SearchQuery::create(['term' => mb_strtolower($term), 'results' => $products->total()]);
            $analytics->push('search', ['search_term' => $term]);
        }
        // Search result pages are thin content to Google; keep them out of the index.
        $seo->title($term ? 'Search results for “'.$term.'”' : 'Search')->noindex()->canonical(route('search'));
        $categories = $term ? Category::active()->where('name', 'like', '%'.$term.'%')->take(6)->get() : collect();
        $popular = SearchQuery::selectRaw('term, count(*) as n')->where('results', '>', 0)->groupBy('term')->orderByDesc('n')->take(10)->pluck('term');

        return view('search', compact('term', 'products', 'categories', 'popular'));
    }

    public function suggest(Request $request)
    {
        $term = trim((string) $request->query('q'));
        if (mb_strlen($term) < 2) {
            return response()->json(['products' => [], 'categories' => []]);
        }

        return response()->json([
            'products' => Product::active()->search($term)->with(['images', 'variants'])->orderByDesc('sales_count')->take(6)->get()
                ->map(fn ($p) => ['name' => $p->name, 'url' => $p->url(), 'image' => $p->mainImageUrl(), 'price' => money($p->currentPrice())]),
            'categories' => Category::active()->where('name', 'like', '%'.$term.'%')->take(4)->get()
                ->map(fn ($c) => ['name' => $c->name, 'url' => $c->url()]),
        ]);
    }

    public function tag(string $tag)
    {
        return redirect(route('search', ['q' => str_replace('-', ' ', $tag)]), 301);
    }
}
