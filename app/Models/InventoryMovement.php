<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** The stock ledger. Append-only: every change to stock is one row here, written by StockLedger. */
#[Fillable([
    'ulid', 'store_id', 'product_id', 'batch_id', 'bucket', 'qty_delta', 'type', 'source_type', 'source_id',
    'cost_rate', 'balance_after', 'user_id', 'note', 'created_at',
])]
class InventoryMovement extends Model
{
    public $timestamps = false;

    protected function casts(): array
    {
        return ['qty_delta' => 'integer', 'balance_after' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Stock movements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Stock movements cannot be deleted.'));
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(StockBatch::class, 'batch_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}
