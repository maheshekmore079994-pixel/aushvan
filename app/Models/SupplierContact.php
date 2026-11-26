<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** A medical representative or other person who visits or takes orders for a supplier (D-054). */
#[Fillable(['store_id', 'supplier_id', 'name', 'role', 'company', 'mobile', 'visit_day', 'schemes', 'notes', 'is_active'])]
class SupplierContact extends Model
{
    public const ROLES = ['mr', 'sales', 'delivery', 'accounts', 'owner', 'other'];

    protected function casts(): array
    {
        return ['visit_day' => 'integer', 'is_active' => 'boolean'];
    }

    public function supplier(): BelongsTo
    {
        return $this->belongsTo(Supplier::class);
    }
}
