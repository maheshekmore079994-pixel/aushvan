<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['store_id', 'product_id', 'product_unit_id', 'barcode', 'is_internal'])]
class ProductBarcode extends Model
{
    protected function casts(): array
    {
        return ['is_internal' => 'boolean'];
    }

    public function productUnit(): BelongsTo
    {
        return $this->belongsTo(ProductUnit::class);
    }
}
