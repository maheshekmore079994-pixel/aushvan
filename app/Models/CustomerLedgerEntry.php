<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One line of a customer's udhar account. Append-only: mistakes are corrected by a new entry. */
#[Fillable([
    'ulid', 'store_id', 'customer_id', 'entry_date', 'type', 'debit_paise', 'credit_paise', 'balance_after_paise',
    'source_type', 'source_id', 'reverses_id', 'note', 'user_id', 'created_at',
])]
class CustomerLedgerEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d', 'created_at' => 'datetime',
            'debit_paise' => 'integer', 'credit_paise' => 'integer', 'balance_after_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }
}
