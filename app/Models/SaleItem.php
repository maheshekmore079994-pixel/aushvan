<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** A bill line: a snapshot of product, batch, price and tax at the moment of sale. */
class SaleItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'expiry_date' => 'date:Y-m-d',
            'qty' => 'integer', 'qty_base' => 'integer', 'unit_factor' => 'integer', 'pack_factor' => 'integer',
            'mrp_paise' => 'integer', 'pack_price_paise' => 'integer', 'gross_paise' => 'integer',
            'discount_paise' => 'integer', 'taxable_paise' => 'integer', 'cgst_paise' => 'integer',
            'sgst_paise' => 'integer', 'igst_paise' => 'integer', 'cess_paise' => 'integer',
            'line_total_paise' => 'integer', 'cost_paise' => 'integer', 'returned_qty_base' => 'integer',
            'discount_percent' => 'decimal:2', 'tax_rate_percent' => 'decimal:2', 'cess_percent' => 'decimal:2',
        ];
    }

    /** A bill line is a snapshot; only the returned-quantity counter may move, and only upwards. */
    protected static function booted(): void
    {
        static::updating(function (SaleItem $item) {
            if (array_keys($item->getDirty()) !== ['returned_qty_base'] || $item->returned_qty_base < (int) $item->getOriginal('returned_qty_base')) {
                throw new LogicException('A bill line cannot be changed.');
            }
        });
        static::deleting(fn () => throw new LogicException('Bill lines cannot be deleted.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }
}
