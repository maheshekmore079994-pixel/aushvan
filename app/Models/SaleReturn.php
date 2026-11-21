<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/** Goods taken back against a bill: a credit note. Never edited or deleted. */
#[Fillable([
    'ulid', 'store_id', 'return_no', 'sale_id', 'customer_id', 'return_date', 'reason', 'refund_mode', 'refund_method',
    'gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise',
    'round_off_paise', 'total_paise', 'cost_paise', 'user_id',
])]
class SaleReturn extends Model
{
    public const MODES = ['refund', 'account_credit'];

    protected function casts(): array
    {
        return [
            'return_date' => 'date:Y-m-d',
            'gross_paise' => 'integer', 'discount_paise' => 'integer', 'taxable_paise' => 'integer',
            'cgst_paise' => 'integer', 'sgst_paise' => 'integer', 'igst_paise' => 'integer', 'cess_paise' => 'integer',
            'round_off_paise' => 'integer', 'total_paise' => 'integer', 'cost_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A return document cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A return document cannot be deleted.'));
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }
}
