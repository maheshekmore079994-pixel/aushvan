<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['ulid', 'store_id', 'reason', 'adjust_date', 'note', 'user_id'])]
class StockAdjustment extends Model
{
    protected function casts(): array
    {
        return ['adjust_date' => 'date'];
    }
}
