<?php

namespace App\Models;

use App\Domain\Shared\ShopClock;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One received lot of a product with its own cost, MRP and expiry.
 * qty_sellable / qty_unsellable are cached totals of the stock ledger and
 * are deliberately not fillable: only StockLedger changes them.
 */
#[Fillable([
    'store_id', 'product_id', 'batch_no', 'mfg_date', 'expiry_date', 'mrp_paise', 'selling_price_paise',
    'cost_rate', 'received_date', 'supplier_id', 'purchase_item_id',
])]
class StockBatch extends Model
{
    protected function casts(): array
    {
        return [
            'mfg_date' => 'date:Y-m-d', 'expiry_date' => 'date:Y-m-d', 'received_date' => 'date:Y-m-d',
            'mrp_paise' => 'integer', 'selling_price_paise' => 'integer',
            'qty_sellable' => 'integer', 'qty_unsellable' => 'integer',
            'cost_rate' => 'decimal:6',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class)->withTrashed();
    }

    /** The purchase line this batch came from; null for opening stock. */
    public function purchaseItem(): BelongsTo
    {
        return $this->belongsTo(PurchaseItem::class, 'purchase_item_id');
    }

    /** Stock may be sold up to and including its expiry date. */
    public function isExpired(): bool
    {
        return $this->expiry_date !== null && $this->expiry_date->toDateString() < ShopClock::todayString();
    }

    /** Negative when already expired; null when the batch has no expiry date. */
    public function daysToExpiry(): ?int
    {
        if ($this->expiry_date === null) {
            return null;
        }

        $expiry = Carbon::parse($this->expiry_date->toDateString(), config('shop.timezone'));

        return (int) ShopClock::today()->diffInDays($expiry, false);
    }
}
