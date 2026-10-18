<?php

namespace App\Http\Controllers;

use App\Models\CustomerUpload;
use App\Services\ImageTools;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Customer photos for personalised products. Uploaded one at a time while the customer is still on the product page. */
class UploadController extends Controller
{
    public function store(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'max:'.config('store.upload_max_kb'), 'mimes:'.implode(',', config('store.upload_mimes'))],
        ], ['file.max' => 'That photo is too large. Please use one under '.round(config('store.upload_max_kb') / 1024).' MB.']);

        $sessionId = $request->session()->getId();
        abort_if(CustomerUpload::where('session_id', $sessionId)->whereNull('order_item_id')->where('created_at', '>', now()->subDay())->count() >= 200, 429, 'Too many uploads.');

        $file = $request->file('file');
        $token = (string) Str::uuid();
        $ext = strtolower($file->getClientOriginalExtension() ?: $file->extension());
        $dir = 'customer-uploads/'.now()->format('Y/m');
        $path = $file->storeAs($dir, $token.'.'.$ext, 'local');

        [$w, $h] = @getimagesize($file->getRealPath()) ?: [null, null];
        $thumb = ImageTools::thumbnail(Storage::disk('local')->path($path), Storage::disk('local')->path($dir.'/'.$token.'_thumb.jpg'), 480)
            ? $dir.'/'.$token.'_thumb.jpg' : null;

        $upload = CustomerUpload::create([
            'token' => $token,
            'session_id' => $sessionId,
            'path' => $path,
            'thumb_path' => $thumb,
            'original_name' => Str::limit($file->getClientOriginalName(), 200, ''),
            'mime' => $file->getMimeType() ?: 'application/octet-stream',
            'size' => $file->getSize(),
            'width' => $w,
            'height' => $h,
        ]);

        return response()->json([
            'token' => $upload->token,
            'thumb' => $upload->thumbUrl(),
            'name' => $upload->original_name,
            'width' => $w,
            'height' => $h,
            // A rough print-quality hint for the customer: under ~800px on the short side prints soft.
            'low_res' => $w && $h && min($w, $h) < 800,
        ]);
    }

    public function destroy(Request $request, string $token)
    {
        $u = CustomerUpload::where('token', $token)->where('session_id', $request->session()->getId())->whereNull('order_item_id')->first();
        if ($u) {
            Storage::disk('local')->delete(array_filter([$u->path, $u->thumb_path]));
            $u->delete();
        }

        return response()->json(['ok' => true]);
    }

    public function show(Request $request, string $token)
    {
        $u = CustomerUpload::where('token', $token)->firstOrFail();
        $allowed = $request->hasValidSignature() || $u->session_id === $request->session()->getId() || $request->user()?->is_admin;
        abort_unless($allowed, 403);
        $thumb = $request->boolean('thumb') && $u->thumb_path;

        return response()->file($u->absolutePath($thumb), [
            'Content-Type' => $thumb ? 'image/jpeg' : $u->mime,
            'Cache-Control' => 'private, max-age=3600',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
