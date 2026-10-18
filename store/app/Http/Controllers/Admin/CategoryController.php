<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CategoryController extends Controller
{
    public function index()
    {
        return view('admin.categories.index', ['categories' => Category::withCount('products')->orderBy('path')->get()]);
    }

    public function create()
    {
        return view('admin.categories.form', ['category' => new Category(['is_active' => true, 'show_on_home' => true]), 'parents' => Category::orderBy('path')->get()]);
    }

    public function store(Request $request)
    {
        $category = new Category;
        $this->save($request, $category);

        return redirect()->route('admin.categories.index')->with('status', 'Category created.');
    }

    public function edit(Category $category)
    {
        $exclude = $category->descendantIds();

        return view('admin.categories.form', ['category' => $category, 'parents' => Category::whereNotIn('id', $exclude)->orderBy('path')->get()]);
    }

    public function update(Request $request, Category $category)
    {
        $this->save($request, $category);

        return redirect()->route('admin.categories.index')->with('status', 'Category saved.');
    }

    public function destroy(Category $category)
    {
        Category::where('parent_id', $category->id)->update(['parent_id' => $category->parent_id]);
        $category->delete(); // its image stays in the media library
        // Children moved up a level: rebuild their paths.
        Category::where('parent_id', $category->parent_id)->get()->each->save();
        Cache::forget('menu.categories');

        return redirect()->route('admin.categories.index')->with('status', 'Category deleted.');
    }

    private function save(Request $request, Category $category): void
    {
        $request->merge(['slug' => Str::slug((string) ($request->input('slug') ?: $request->input('name')))]);
        $exclude = $category->exists ? $category->descendantIds() : [];
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'slug' => ['required', 'string', 'max:255', Rule::unique('categories', 'slug')->ignore($category->id)],
            'parent_id' => ['nullable', 'integer', 'exists:categories,id', Rule::notIn($exclude)],
            'description' => 'nullable|string',
            // A path chosen in the media library, or (fallback) an uploaded file
            'image' => $request->hasFile('image') ? 'image|max:10240' : ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) use ($category) {
                if ($value !== $category->image && ! Media::acceptable($value)) {
                    $fail('That image is not in the media library.');
                }
            }],
            'icon' => 'nullable|string|max:40',
            'position' => 'nullable|integer',
            'meta_title' => 'nullable|string|max:255',
            'meta_description' => 'nullable|string|max:320',
            'seo_content' => 'nullable|string',
        ]);
        $category->fill([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'parent_id' => $data['parent_id'] ?? null,
            'description' => $data['description'] ?? null,
            'icon' => $data['icon'] ?? null,
            'position' => $data['position'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'show_on_home' => $request->boolean('show_on_home'),
            'meta_title' => $data['meta_title'] ?? null,
            'meta_description' => $data['meta_description'] ?? null,
            'seo_content' => $data['seo_content'] ?? null,
        ]);
        if ($request->hasFile('image')) {
            $category->image = MediaController::storeUpload($request->file('image'), 'categories', $data['name'])->path;
        } elseif ($request->has('image')) {
            $category->image = ($data['image'] ?? null) ?: null;
            if ($category->image) {
                Media::register($category->image, 'categories');
            }
        }
        $category->save();
        Cache::forget('menu.categories');
    }
}
