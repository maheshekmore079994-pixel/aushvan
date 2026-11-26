<?php

namespace App\Actions;

use App\Domain\Cash\DayBook;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Starts the cash day with the float counted in the drawer. One open day per shop; one session per date. */
final class OpenDay
{
    public function __construct(private DayBook $book) {}

    public function handle(User $user, int $openingCashPaise): CashSession
    {
        return DB::transaction(function () use ($user, $openingCashPaise) {
            // Serialise on the shop row so two operators cannot open two days at once.
            DB::table('stores')->where('id', $user->store_id)->lockForUpdate()->first();
            if ($this->book->open($user->store_id) !== null) {
                throw new BusinessRuleException('day_already_open', 'A day is already open.');
            }
            $today = ShopClock::todayString();
            if (CashSession::where('store_id', $user->store_id)->where('business_date', $today)->exists()) {
                throw new BusinessRuleException('day_already_closed', 'Today was already closed. The owner can reopen it.');
            }

            $previous = $this->book->lastClosed($user->store_id);
            $session = CashSession::create([
                'store_id' => $user->store_id,
                'business_date' => $today,
                'status' => CashSession::OPEN,
                'opened_by' => $user->id,
                'opened_at' => now(),
                'opening_cash_paise' => $openingCashPaise,
            ])->refresh();
            // Bills made after last night's count belong to this drawer. (Before the very first day the float covers it.)
            $adopted = $previous?->closed_at ? $this->book->adoptOrphans($session, $previous->closed_at) : 0;
            Audit::log('day.opened', $session, new: ['opening_cash_paise' => $openingCashPaise, 'adopted_payments' => $adopted]);

            return $session;
        });
    }
}
