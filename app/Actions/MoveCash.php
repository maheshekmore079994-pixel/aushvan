<?php

namespace App\Actions;

use App\Domain\Cash\DayBook;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Cash put into or taken out of the drawer on the open day: a float, a bank deposit, the owner taking money. */
final class MoveCash
{
    public function __construct(private DayBook $book) {}

    public function handle(User $user, string $type, int $amountPaise, ?string $note = null): CashMovement
    {
        return DB::transaction(function () use ($user, $type, $amountPaise, $note) {
            $session = CashSession::where('store_id', $user->store_id)->where('status', CashSession::OPEN)->orderByDesc('business_date')->lockForUpdate()->first();
            if ($session === null) {
                throw new BusinessRuleException('day_not_open', 'Open the day before moving cash.');
            }
            if ($type !== CashMovement::CASH_IN) {
                $expected = $this->book->figures($session)['expected_cash_paise'];
                if ($amountPaise > $expected) {
                    throw new BusinessRuleException('not_enough_cash', 'There is not that much cash in the drawer.', ['expected_cash_paise' => $expected]);
                }
            }
            $movement = CashMovement::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $user->store_id,
                'cash_session_id' => $session->id,
                'type' => $type,
                'amount_paise' => $amountPaise,
                'note' => $note,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
            Audit::log('day.cash_moved', $session, new: ['type' => $type, 'amount_paise' => $amountPaise, 'note' => $note]);

            return $movement;
        });
    }
}
