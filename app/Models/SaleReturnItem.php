<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** One returned line: which bill line, how much, where the goods went and the money given back for it. */
class SaleReturnItem extends Model
{
    public $timestamps = false;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'qty' => 'integer', 'qty_base' => 'integer', 'gross_paise' => 'integer', 'discount_paise' => 'integer',
            'taxable_paise' => 'integer', 'cgst_paise' => 'integer', 'sgst_paise' => 'integer', 'igst_paise' => 'integer',
            'cess_paise' => 'integer', 'line_total_paise' => 'integer', 'cost_paise' => 'integer',
        ];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('A return line cannot be changed.'));
        static::deleting(fn () => throw new LogicException('A return line cannot be deleted.'));
    }

    public function saleItem(): BelongsTo
    {
        return $this->belongsTo(SaleItem::class);
    }
}
