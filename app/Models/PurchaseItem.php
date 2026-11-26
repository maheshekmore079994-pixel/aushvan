<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One line of a purchase. Lines of a draft are replaced on every save; lines of a confirmed purchase never change. */
#[Fillable([
    'purchase_id', 'line_no', 'product_id', 'product_name', 'hsn_code', 'unit_code', 'unit_factor', 'qty', 'free_qty',
    'qty_base', 'rate_paise', 'discount_percent', 'gross_paise', 'discount_paise', 'tax_rate_percent', 'cess_percent',
    'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise', 'line_total_paise', 'batch_no', 'mfg_date',
    'expiry_date', 'mrp_paise', 'selling_price_paise', 'cost_rate', 'batch_id',
])]
class PurchaseItem extends Model
{
    protected function casts(): array
    {
        return [
            'mfg_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d',
            'unit_factor' => 'integer', 'qty' => 'integer', 'free_qty' => 'integer', 'qty_base' => 'integer',
            'rate_paise' => 'integer', 'gross_paise' => 'integer', 'discount_paise' => 'integer',
            'taxable_paise' => 'integer', 'cgst_paise' => 'integer', 'sgst_paise' => 'integer', 'igst_paise' => 'integer',
            'cess_paise' => 'integer', 'line_total_paise' => 'integer', 'mrp_paise' => 'integer',
            'selling_price_paise' => 'integer', 'cost_rate' => 'decimal:6',
        ];
    }

    protected static function booted(): void
    {
        static::updating(function (PurchaseItem $item) {
            $purchase = $item->purchase()->first();
            if ($purchase !== null && ! $purchase->isDraft()) {
                throw new LogicException('Lines of a confirmed purchase cannot be changed.');
            }
        });
        static::deleting(function (PurchaseItem $item) {
            $purchase = $item->purchase()->first();
            if ($purchase !== null && ! $purchase->isDraft()) {
                throw new LogicException('Lines of a confirmed purchase cannot be deleted.');
            }
        });
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
