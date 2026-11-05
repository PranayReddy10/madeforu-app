<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Media;
use App\Services\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

class MediaController extends Controller
{
    public const FOLDERS = ['products' => 'Products', 'categories' => 'Categories', 'banners' => 'Banners', 'general' => 'General'];

    public function index(Request $request)
    {
        $q = Media::query()->latest('id');
        if ($s = trim((string) $request->query('q'))) {
            $q->where(fn ($w) => $w->where('name', 'like', "%{$s}%")->orWhere('alt', 'like', "%{$s}%"));
        }
        if (($f = $request->query('folder')) && isset(self::FOLDERS[$f])) {
            $q->where('folder', $f);
        }
        $media = $q->paginate(60)->withQueryString();

        if ($request->wantsJson()) {
            return response()->json([
                'data' => collect($media->items())->map->toPicker()->values(),
                'next_page_url' => $media->nextPageUrl(),
            ]);
        }

        return view('admin.media.index', ['media' => $media, 'folders' => self::FOLDERS]);
    }

    public function store(Request $request)
    {
        $request->validate([
            'files' => 'required|array|max:50',
            'files.*' => 'file|mimes:jpg,jpeg,png,webp,gif,avif|max:10240',
            'folder' => 'nullable|in:'.implode(',', array_keys(self::FOLDERS)),
        ], ['files.*.mimes' => 'Only images can be uploaded (JPG, PNG, WebP, GIF).', 'files.*.max' => 'Each image must be under 10 MB.']);

        $folder = $request->input('folder') ?: 'general';
        $saved = collect($request->file('files'))->map(fn (UploadedFile $f) => self::storeUpload($f, $folder));

        if ($request->wantsJson()) {
            return response()->json(['data' => $saved->map->toPicker()->values()]);
        }

        return back()->with('status', $saved->count().' '.Str::plural('image', $saved->count()).' uploaded.');
    }

    public function update(Request $request, Media $media)
    {
        $data = $request->validate(['alt' => 'nullable|string|max:255', 'name' => 'nullable|string|max:255']);
        $media->update(['alt' => $data['alt'] ?? null, 'name' => ($data['name'] ?? null) ?: $media->name]);

        return $request->wantsJson() ? response()->json(['data' => $media->toPicker()]) : back()->with('status', 'Image details saved.');
    }

    public function destroy(Request $request, Media $media)
    {
        if ($media->inUse()) {
            $msg = 'This image is used by a product, category or banner. Remove it there first.';

            return $request->wantsJson() ? response()->json(['message' => $msg], 422) : back()->with('error', $msg);
        }
        if (! $media->isRemote()) {
            media_disk()->delete([$media->path, preg_replace('/\.\w+$/', '.webp', $media->path)]);
        }
        $media->delete();

        return $request->wantsJson() ? response()->json(['ok' => true]) : back()->with('status', 'Image deleted.');
    }

    /** Save an uploaded image to the media disk under media/YYYY/MM and add it to the library. */
    public static function storeUpload(UploadedFile $file, string $folder = 'general', ?string $alt = null): Media
    {
        $disk = media_disk();
        $base = Str::slug(pathinfo($file->getClientOriginalName(), PATHINFO_FILENAME)) ?: 'image';
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
        $dir = 'media/'.now()->format('Y/m');
        $name = Str::limit($base, 80, '').'.'.$ext;
        if ($disk->exists($dir.'/'.$name)) {
            $name = Str::limit($base, 80, '').'-'.Str::lower(Str::random(6)).'.'.$ext;
        }
        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];
        $path = $disk->putFileAs($dir, $file, $name);
        if (config('store.media_disk') === 'public' && in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
            ImageTools::variants($disk->path($path));
        }

        return Media::create([
            'path' => $path,
            'name' => $name,
            'alt' => $alt,
            'mime' => $file->getMimeType(),
            'size' => $file->getSize(),
            'width' => $w,
            'height' => $h,
            'folder' => $folder,
        ]);
    }
}
