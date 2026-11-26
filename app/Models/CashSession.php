<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * One business day of the cash drawer: opened with a counted float, closed with a counted total. A closed day
 * keeps the figures as they were (summary); only reopening by the owner (logged, with a reason) changes it.
 */
#[Fillable([
    'store_id', 'business_date', 'status', 'opened_by', 'opened_at', 'opening_cash_paise', 'closed_by', 'closed_at',
    'expected_cash_paise', 'counted_cash_paise', 'difference_paise', 'summary', 'note', 'reopened_by', 'reopened_at', 'reopen_reason',
])]
class CashSession extends Model
{
    public const OPEN = 'open';

    public const CLOSED = 'closed';

    protected function casts(): array
    {
        return [
            'business_date' => 'date:Y-m-d', 'opened_at' => 'datetime', 'closed_at' => 'datetime', 'reopened_at' => 'datetime',
            'opening_cash_paise' => 'integer', 'expected_cash_paise' => 'integer', 'counted_cash_paise' => 'integer',
            'difference_paise' => 'integer', 'summary' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(fn () => throw new LogicException('A cash day cannot be deleted.'));
        static::updating(function (CashSession $session) {
            // A closed day changes only by reopening it (status back to open with the reopen fields set).
            if ($session->getOriginal('status') === self::CLOSED && $session->status === self::CLOSED) {
                throw new LogicException('A closed day cannot be changed; reopen it first.');
            }
        });
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }

    public function movements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function opener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'opened_by')->withTrashed();
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by')->withTrashed();
    }
}
