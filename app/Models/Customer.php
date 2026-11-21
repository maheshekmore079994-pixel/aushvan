<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** balance_paise is a cached total of the ledger and is changed only by CustomerLedger. */
#[Fillable(['ulid', 'store_id', 'name', 'mobile', 'village', 'address', 'credit_limit_paise', 'notes', 'search_text', 'is_active'])]
class Customer extends Model
{
    use SoftDeletes;

    protected function casts(): array
    {
        return [
            'credit_limit_paise' => 'integer', 'balance_paise' => 'integer',
            'is_active' => 'boolean', 'last_payment_at' => 'datetime',
        ];
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(CustomerLedgerEntry::class);
    }
}
