<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomizationField extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['options' => 'array', 'required' => 'boolean', 'extra_price' => 'decimal:2'];

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function inputName(): string
    {
        return 'custom['.$this->id.']';
    }
}
