<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;

class CustomerUpload extends Model
{
    protected $guarded = ['id'];

    /** Customer photos are private: only a signed link (admin, or the uploader's own session) can see them. */
    public function thumbUrl(): string
    {
        return URL::temporarySignedRoute('upload.show', now()->addHours(12), ['token' => $this->token, 'thumb' => 1]);
    }

    public function fullUrl(): string
    {
        return URL::temporarySignedRoute('upload.show', now()->addHours(12), ['token' => $this->token]);
    }

    public function absolutePath(bool $thumb = false): string
    {
        return Storage::disk('local')->path($thumb && $this->thumb_path ? $this->thumb_path : $this->path);
    }
}
