<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** One line of a supplier's statement. Append-only; written by SupplierLedger alone. */
#[Fillable([
    'ulid', 'store_id', 'supplier_id', 'entry_date', 'type', 'credit_paise', 'debit_paise', 'balance_after_paise',
    'source_type', 'source_id', 'reverses_id', 'note', 'user_id', 'created_at',
])]
class SupplierLedgerEntry extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return [
            'entry_date' => 'date:Y-m-d', 'credit_paise' => 'integer', 'debit_paise' => 'integer',
            'balance_after_paise' => 'integer', 'created_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Ledger entries cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Ledger entries cannot be deleted.'));
    }
}
