<?php

namespace App\Http\Controllers;

use App\Models\Banner;
use App\Models\Category;
use App\Models\Product;
use App\Models\Review;
use App\Services\Seo;

class HomeController extends Controller
{
    public function index(Seo $seo)
    {
        $seo->title(setting('home_title') ?: config('store.name').' - Personalised Gifts Online India | Custom Photo Gifts')
            ->description(setting('home_description') ?: 'Personalised photo gifts made for you: custom frames, mugs, cushions, keychains and more. Upload your photos, we make it and deliver across India.')
            ->canonical(url('/'));
        foreach ($seo->siteSchema() as $s) {
            $seo->addSchema($s);
        }

        $with = ['images', 'variants', 'primaryCategory', 'customizationFields'];

        return view('home', [
            'banners' => Banner::where('is_active', true)->orderBy('position')->get(),
            'categories' => Category::active()->where('show_on_home', true)->whereNull('parent_id')->whereIn('id', Category::withShopProductsIds())->withShopCount()->orderBy('position')->orderBy('name')->get(),
            'featured' => Product::active()->with($with)->where('is_featured', true)->latest()->take(12)->get(),
            'bestsellers' => Product::active()->with($with)->orderByDesc('sales_count')->orderByDesc('views')->take(12)->get(),
            'newest' => Product::active()->with($with)->latest()->take(12)->get(),
            'reviews' => Review::where('is_approved', true)->where('rating', '>=', 4)->whereNotNull('body')->with('product')->latest()->take(8)->get(),
        ]);
    }
}
