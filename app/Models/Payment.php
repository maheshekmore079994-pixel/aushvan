<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use LogicException;

/** Every rupee that comes into or goes out of the shop is one row here. Append-only. */
#[Fillable([
    'ulid', 'store_id', 'direction', 'method', 'amount_paise', 'party_type', 'party_id', 'payable_type', 'payable_id',
    'reference_no', 'business_date', 'cash_session_id', 'user_id', 'note', 'created_at',
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
        // Tied to the open cash day inside the payment's own transaction. The day's row is locked for the rest of
        // the transaction, so a closing happening at the same moment either waits and then counts this payment,
        // or finishes first and this payment lands in the next day. Never in neither.
        static::creating(function (Payment $payment) {
            if ($payment->cash_session_id === null && DB::transactionLevel() > 0) {
                $payment->cash_session_id = CashSession::where('store_id', $payment->store_id)->where('status', CashSession::OPEN)
                    ->orderByDesc('business_date')->lockForUpdate()->value('id');
            }
        });
        static::updating(fn () => throw new LogicException('Payments cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Payments cannot be deleted.'));
    }
}
