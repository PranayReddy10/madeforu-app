<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Support\HomeLayout;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

class HomePageController extends Controller
{
    public function edit()
    {
        return view('admin.home.edit', [
            'sections' => HomeLayout::get(),
            'categories' => Category::orderBy('path')->get(['id', 'name', 'path']),
            'products' => Product::active()->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function update(Request $request)
    {
        if ($request->boolean('reset')) {
            HomeLayout::reset();

            return redirect()->route('admin.home.edit')->with('status', 'Home page set back to the standard layout.');
        }
        $request->validate(['sections' => 'required|array|max:40']);
        // The browser sends sections in the order they are on screen; each knows its own type.
        HomeLayout::save(array_values($request->input('sections')));
        Cache::forget('menu.categories');

        return redirect()->route('admin.home.edit')->with('status', 'Home page saved.');
    }
}
