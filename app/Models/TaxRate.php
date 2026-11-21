<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/** GST rates are data. rate_percent and cess_percent are decimal strings. */
#[Fillable(['name', 'rate_percent', 'cess_percent', 'kind', 'is_active'])]
class TaxRate extends Model
{
    protected function casts(): array
    {
        return ['rate_percent' => 'decimal:2', 'cess_percent' => 'decimal:2', 'is_active' => 'boolean'];
    }
}
