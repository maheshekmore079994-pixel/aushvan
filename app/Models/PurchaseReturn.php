<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/** Goods sent back to a supplier: a debit note. Never edited or deleted. */
#[Fillable([
    'ulid', 'store_id', 'return_no', 'supplier_id', 'return_date', 'reason', 'taxable_paise', 'cgst_paise', 'sgst_paise',
    'igst_paise', 'cess_paise', 'round_off_paise', 'total_paise', 'settlement', 'refund_method', 'user_id',
])]
class PurchaseReturn extends Model
{
    public const SETTLEMENTS = ['reduce_balance', 'refund_received'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date:Y-m-d', 'taxable_paise' => 'integer', 'cgst_paise' => 'integer', 'sgst_paise' => 'integer',
            'igst_paise' => 'integer', 'cess_paise' => 'integer', 'round_off_paise' => 'integer', 'total_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A debit note cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A debit note cannot be deleted.'));
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseReturnItem::class)->orderBy('line_no');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
