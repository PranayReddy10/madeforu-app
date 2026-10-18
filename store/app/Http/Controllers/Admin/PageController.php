<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Page;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PageController extends Controller
{
    public function index()
    {
        return view('admin.pages.index', ['pages' => Page::orderBy('title')->get()]);
    }

    public function create()
    {
        return view('admin.pages.form', ['page' => new Page(['is_active' => true])]);
    }

    public function store(Request $request)
    {
        Page::create($this->data($request, new Page));

        return redirect()->route('admin.pages.index')->with('status', 'Page created.');
    }

    public function edit(Page $page)
    {
        return view('admin.pages.form', compact('page'));
    }

    public function update(Request $request, Page $page)
    {
        $page->update($this->data($request, $page));

        return redirect()->route('admin.pages.index')->with('status', 'Page saved.');
    }

    public function destroy(Page $page)
    {
        $page->delete();

        return redirect()->route('admin.pages.index')->with('status', 'Page deleted.');
    }

    private function data(Request $request, Page $page): array
    {
        // Paths may be nested like WordPress pages (legal/privacy-policy): slug each part separately.
        $slug = collect(explode('/', (string) ($request->input('slug') ?: $request->input('title'))))
            ->map(fn ($part) => Str::slug($part))->filter()->implode('/');
        $request->merge(['slug' => $slug]);
        // These paths belong to the store itself.
        $reserved = ['shop', 'cart', 'checkout', 'search', 'login', 'register', 'logout', 'admin', 'categories', 'wishlist', 'my-account', 'track-order', 'product', 'product-category', 'uploads', 'feeds', 'up'];
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('pages', 'slug')->ignore($page->id),
                fn ($attr, $value, $fail) => in_array(explode('/', $value)[0], $reserved, true) ? $fail('That address is used by the store itself.') : null],
            'body' => 'nullable|string',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
        ]);

        return $data + ['is_active' => $request->boolean('is_active')];
    }
}
