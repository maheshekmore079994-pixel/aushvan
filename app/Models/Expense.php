<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use LogicException;

/** Money the shop spent on something other than stock. Its payment is a `payments` row out; cancelling mirrors it back in. */
#[Fillable([
    'ulid', 'store_id', 'expense_category_id', 'amount_paise', 'expense_date', 'method', 'description', 'reference_no', 'user_id',
    'cancelled_by', 'cancelled_at', 'cancel_reason',
])]
class Expense extends Model
{
    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'expense_date' => 'date:Y-m-d', 'cancelled_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(function (Expense $expense) {
            $allowed = ['cancelled_by', 'cancelled_at', 'cancel_reason', 'updated_at'];
            if (array_diff(array_keys($expense->getDirty()), $allowed) !== [] || $expense->getOriginal('cancelled_at') !== null) {
                throw new LogicException('An expense cannot be changed; cancel it and enter it again.');
            }
        });
        static::deleting(fn () => throw new LogicException('Expenses cannot be deleted.'));
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class, 'expense_category_id');
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
