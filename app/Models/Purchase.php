<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/**
 * A supplier invoice entered into the shop. A draft can be edited and deleted freely; once confirmed it has
 * created stock and a ledger entry and may only be cancelled (when none of its stock has moved) or
 * returned against. paid_paise is a cached total of the payments made against it.
 */
#[Fillable([
    'ulid', 'store_id', 'purchase_no', 'status', 'supplier_id', 'supplier_invoice_no', 'invoice_date', 'received_date',
    'gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise',
    'other_charges_paise', 'round_off_paise', 'total_paise', 'invoice_total_paise', 'paid_paise', 'inter_state',
    'due_date', 'notes', 'user_id', 'confirmed_by', 'confirmed_at', 'cancelled_by', 'cancelled_at', 'cancel_reason',
])]
class Purchase extends Model
{
    public const DRAFT = 'draft';

    public const CONFIRMED = 'confirmed';

    public const CANCELLED = 'cancelled';

    protected function casts(): array
    {
        return [
            'invoice_date' => 'date:Y-m-d', 'received_date' => 'date:Y-m-d', 'due_date' => 'date:Y-m-d',
            'confirmed_at' => 'datetime', 'cancelled_at' => 'datetime', 'inter_state' => 'boolean',
            'gross_paise' => 'integer', 'discount_paise' => 'integer', 'taxable_paise' => 'integer',
            'cgst_paise' => 'integer', 'sgst_paise' => 'integer', 'igst_paise' => 'integer', 'cess_paise' => 'integer',
            'other_charges_paise' => 'integer', 'round_off_paise' => 'integer', 'total_paise' => 'integer',
            'invoice_total_paise' => 'integer', 'paid_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        // A confirmed purchase is a finalised document: only its cached payment total and its cancellation may change.
        static::updating(function (Purchase $purchase) {
            if ($purchase->getOriginal('status') === self::DRAFT) {
                return;
            }
            $allowed = ['paid_paise', 'status', 'cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_at'];
            if (array_diff(array_keys($purchase->getDirty()), $allowed) !== []) {
                throw new LogicException('A confirmed purchase cannot be changed; cancel it or make a return.');
            }
        });
        static::deleting(function (Purchase $purchase) {
            if ($purchase->status !== self::DRAFT) {
                throw new LogicException('Only a draft purchase can be deleted.');
            }
        });
    }

    public function isDraft(): bool
    {
        return $this->status === self::DRAFT;
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class)->orderBy('line_no');
    }

    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
