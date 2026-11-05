<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Redirect extends Model
{
    protected $guarded = ['id'];

    public static function normalise(string $path): string
    {
        return '/'.trim(strtolower(rawurldecode($path)), '/');
    }
}
