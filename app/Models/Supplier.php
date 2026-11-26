<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** balance_paise (positive = the shop owes the supplier) is a cached total of the ledger; only SupplierLedger changes it. */
#[Fillable([
    'ulid', 'store_id', 'name', 'kind', 'contact_person', 'mobile', 'address', 'gstin', 'drug_licence_no',
    'payment_terms_days', 'notes', 'search_text', 'is_active',
])]
class Supplier extends Model
{
    use SoftDeletes;

    public const KINDS = ['distributor', 'wholesaler', 'manufacturer', 'other'];

    protected function casts(): array
    {
        return [
            'payment_terms_days' => 'integer', 'balance_paise' => 'integer',
            'is_active' => 'boolean', 'last_payment_at' => 'datetime',
        ];
    }

    public function ledger(): HasMany
    {
        return $this->hasMany(SupplierLedgerEntry::class);
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(SupplierContact::class);
    }

    public function purchases(): HasMany
    {
        return $this->hasMany(Purchase::class);
    }

    /** The state code is the first two digits of a GSTIN; null when the supplier has none. */
    public function stateCode(): ?string
    {
        return $this->gstin !== null && strlen($this->gstin) >= 2 ? substr($this->gstin, 0, 2) : null;
    }
}
