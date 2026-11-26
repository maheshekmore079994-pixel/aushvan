<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Cash put into or taken out of the drawer without a bill, purchase or expense. Append-only; a mistake is undone by the opposite movement. */
#[Fillable(['ulid', 'store_id', 'cash_session_id', 'type', 'amount_paise', 'note', 'user_id', 'created_at'])]
class CashMovement extends Model
{
    public const CASH_IN = 'cash_in';

    public const BANK_DEPOSIT = 'bank_deposit';

    public const OWNER_WITHDRAWAL = 'owner_withdrawal';

    public const OTHER_OUT = 'other_out';

    public const TYPES = [self::CASH_IN, self::BANK_DEPOSIT, self::OWNER_WITHDRAWAL, self::OTHER_OUT];

    public $timestamps = false;

    protected function casts(): array
    {
        return ['amount_paise' => 'integer', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Cash movements cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Cash movements cannot be deleted.'));
    }

    public function isIn(): bool
    {
        return $this->type === self::CASH_IN;
    }
}
