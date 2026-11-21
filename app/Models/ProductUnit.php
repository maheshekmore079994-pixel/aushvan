<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['product_id', 'unit_id', 'factor_to_base', 'price_override_paise', 'is_base', 'is_pricing'])]
class ProductUnit extends Model
{
    protected function casts(): array
    {
        return ['factor_to_base' => 'integer', 'price_override_paise' => 'integer', 'is_base' => 'boolean', 'is_pricing' => 'boolean'];
    }

    public function unit(): BelongsTo
    {
        return $this->belongsTo(Unit::class);
    }
}
