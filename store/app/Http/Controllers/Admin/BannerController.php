<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

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
        foreach ([$banner->image, $banner->mobile_image] as $img) {
            if ($img && ! preg_match('#^https?://#', $img)) {
                Storage::disk('public')->delete($img);
            }
        }
        $banner->delete();

        return redirect()->route('admin.banners.index')->with('status', 'Banner deleted.');
    }

    private function save(Request $request, Banner $banner): void
    {
        $data = $request->validate([
            'title' => 'required|string|max:255',
            'subtitle' => 'nullable|string|max:255',
            'image' => [$banner->exists ? 'nullable' : 'required', 'image', 'max:10240'],
            'mobile_image' => ['nullable', 'image', 'max:10240'],
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
        if ($request->hasFile('image')) {
            if ($banner->image && ! preg_match('#^https?://#', $banner->image)) {
                Storage::disk('public')->delete($banner->image);
            }
            $banner->image = $request->file('image')->store('banners', 'public');
        }
        if ($request->hasFile('mobile_image')) {
            if ($banner->mobile_image && ! preg_match('#^https?://#', $banner->mobile_image)) {
                Storage::disk('public')->delete($banner->mobile_image);
            }
            $banner->mobile_image = $request->file('mobile_image')->store('banners', 'public');
        } elseif ($request->boolean('remove_mobile_image') && $banner->mobile_image) {
            Storage::disk('public')->delete($banner->mobile_image);
            $banner->mobile_image = null;
        }
        $banner->save();
    }
}
