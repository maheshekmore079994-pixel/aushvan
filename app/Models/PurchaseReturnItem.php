<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One batch quantity on a debit note. */
#[Fillable([
    'purchase_return_id', 'line_no', 'product_id', 'product_name', 'batch_id', 'batch_no', 'bucket', 'qty_base', 'cost_rate',
    'tax_rate_percent', 'cess_percent', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise', 'line_total_paise',
])]
class PurchaseReturnItem extends Model
{
    protected function casts(): array
    {
        return [
            'qty_base' => 'integer', 'cost_rate' => 'decimal:6', 'taxable_paise' => 'integer', 'cgst_paise' => 'integer',
            'sgst_paise' => 'integer', 'igst_paise' => 'integer', 'cess_paise' => 'integer', 'line_total_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A debit note line cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A debit note line cannot be deleted.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
