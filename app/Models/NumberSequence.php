<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['store_id', 'doc_type', 'prefix', 'financial_year', 'next_number'])]
class NumberSequence extends Model
{
    protected function casts(): array
    {
        return ['next_number' => 'integer'];
    }
}
