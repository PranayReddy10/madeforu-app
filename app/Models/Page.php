<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['is_active' => 'boolean'];

    protected static function booted(): void
    {
        $forget = fn () => \Illuminate\Support\Facades\Cache::forget('menu.pages');
        static::saved($forget);
        static::deleted($forget);
    }

    public function url(): string
    {
        return url($this->slug).'/';
    }
}
