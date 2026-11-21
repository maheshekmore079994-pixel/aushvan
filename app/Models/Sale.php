<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/** A completed bill. Never edited; corrected only by a cancellation or return document. */
#[Fillable([
    'ulid', 'store_id', 'invoice_no', 'doc_kind', 'status', 'sale_date', 'completed_at', 'customer_id', 'buyer_name',
    'gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise',
    'round_off_paise', 'total_paise', 'paid_paise', 'credit_paise', 'cost_paise', 'prescription_id',
    'verified_by_pharmacist_id', 'user_id', 'notes', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason',
])]
class Sale extends Model
{
    protected function casts(): array
    {
        return [
            'sale_date' => 'date:Y-m-d', 'completed_at' => 'datetime',
            'gross_paise' => 'integer', 'discount_paise' => 'integer', 'taxable_paise' => 'integer',
            'cgst_paise' => 'integer', 'sgst_paise' => 'integer', 'igst_paise' => 'integer', 'cess_paise' => 'integer',
            'round_off_paise' => 'integer', 'total_paise' => 'integer', 'paid_paise' => 'integer',
            'credit_paise' => 'integer', 'cost_paise' => 'integer', 'cancelled_at' => 'datetime',
        ];
    }

    /** Only the cancellation fields and the cached return counters may ever change on a completed bill. */
    protected static function booted(): void
    {
        static::updating(function (Sale $sale) {
            $allowed = ['status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_at'];
            if (array_diff(array_keys($sale->getDirty()), $allowed) !== []) {
                throw new LogicException('A completed bill cannot be changed; cancel it or make a return.');
            }
        });
        static::deleting(fn () => throw new LogicException('Bills cannot be deleted.'));
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class)->orderBy('id');
    }

    public function cancelledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'cancelled_by')->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class)->orderBy('line_no');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function prescription(): BelongsTo
    {
        return $this->belongsTo(Prescription::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }

    public function pharmacist(): BelongsTo
    {
        return $this->belongsTo(User::class, 'verified_by_pharmacist_id')->withTrashed();
    }
}
