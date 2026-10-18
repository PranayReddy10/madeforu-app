<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class BannerController extends Controller
{
    public function index()
    {
        return view('admin.banners.index', ['banners' => Banner::orderBy('position')->get()]);
    }

    public function create()
    {
        return view('admin.banners.form', ['banner' => new Banner(['is_active' => true, 'show_text' => true, 'position' => 0])]);
    }

    public function store(Request $request)
    {
        $banner = new Banner;
        $this->save($request, $banner);

        return redirect()->route('admin.banners.index')->with('status', 'Banner created.');
    }

    public function edit(Banner $banner)
    {
        return view('admin.banners.form', compact('banner'));
    }

    public function update(Request $request, Banner $banner)
    {
        $this->save($request, $banner);

        return redirect()->route('admin.banners.index')->with('status', 'Banner saved.');
    }

    public function destroy(Banner $banner)
    {
        $banner->delete(); // its images stay in the media library

        return redirect()->route('admin.banners.index')->with('status', 'Banner deleted.');
    }

    private function save(Request $request, Banner $banner): void
    {
        // Each image is a path chosen in the media library, or (fallback) an uploaded file.
        $pathRule = fn (?string $current) => ['nullable', 'string', 'max:1000', function ($attr, $value, $fail) use ($current) {
            if ($value !== $current && ! Media::acceptable($value)) {
                $fail('That image is not in the media library.');
            }
        }];
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image' => $request->hasFile('image') ? ['image', 'max:10240'] : $pathRule($banner->image),
            'mobile_image' => $request->hasFile('mobile_image') ? ['image', 'max:10240'] : $pathRule($banner->mobile_image),
            'link' => 'nullable|string|max:255',
            'button' => 'nullable|string|max:60',
            'position' => 'nullable|integer',
        ]);
        $banner->fill([
            'title' => $data['title'],
            'subtitle' => $data['subtitle'] ?? null,
            'link' => $data['link'] ?? null,
            'button' => $data['button'] ?? null,
            'position' => $data['position'] ?? 0,
            'is_active' => $request->boolean('is_active'),
            'show_text' => $request->boolean('show_text'),
        ]);
        foreach (['image', 'mobile_image'] as $field) {
            if ($request->hasFile($field)) {
                $banner->{$field} = MediaController::storeUpload($request->file($field), 'banners', $data['title'])->path;
            } elseif ($request->has($field)) {
                $banner->{$field} = ($data[$field] ?? null) ?: null;
                if ($banner->{$field}) {
                    Media::register($banner->{$field}, 'banners', $data['title']);
                }
            }
        }
        if ($request->boolean('remove_mobile_image')) {
            $banner->mobile_image = null;
        }
        if (! $banner->image) {
            throw ValidationException::withMessages(['image' => 'Choose a desktop image for the banner.']);
        }
        $banner->save();
    }
}
