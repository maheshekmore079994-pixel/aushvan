<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Every rupee that comes into or goes out of the shop is one row here. Append-only. */
#[Fillable([
    'ulid', 'store_id', 'direction', 'method', 'amount_paise', 'party_type', 'party_id', 'payable_type', 'payable_id',
    'reference_no', 'business_date', 'user_id', 'note', 'created_at',
])]
class Payment extends Model
{
    public const METHODS = ['cash', 'upi', 'card', 'bank'];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'business_date' => 'date:Y-m-d', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }
}
