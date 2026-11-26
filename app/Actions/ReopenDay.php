<?php

namespace App\Actions;

use App\Domain\Cash\DayBook;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * The owner reopens a closed day (only the latest one, and only while no later day is open), with a reason
 * that is logged. The closing figures are cleared; closing again computes them afresh.
 */
final class ReopenDay
{
    public function __construct(private DayBook $book) {}

    public function handle(User $user, CashSession $session, string $reason): CashSession
    {
        return DB::transaction(function () use ($user, $session, $reason) {
            // Same lock order as OpenDay (shop row first), so a reopen and an open cannot both succeed.
            DB::table('stores')->where('id', $session->store_id)->lockForUpdate()->first();
            $session = CashSession::whereKey($session->id)->lockForUpdate()->firstOrFail();
            if ($session->status !== CashSession::CLOSED) {
                throw new BusinessRuleException('day_not_closed', 'This day is not closed.');
            }
            if ($this->book->open($session->store_id) !== null) {
                throw new BusinessRuleException('day_already_open', 'Close the open day first.');
            }
            $later = CashSession::where('store_id', $session->store_id)->where('business_date', '>', $session->business_date->toDateString())->exists();
            if ($later) {
                throw new BusinessRuleException('day_not_latest', 'Only the most recent closed day can be reopened.');
            }

            $before = $session->only(['closed_by', 'closed_at', 'expected_cash_paise', 'counted_cash_paise', 'difference_paise', 'note']);
            // Bills made after the count now belong to this day again.
            $adopted = $session->closed_at ? $this->book->adoptOrphans($session, $session->closed_at) : 0;
            $session->fill([
                'status' => CashSession::OPEN,
                'closed_by' => null,
                'closed_at' => null,
                'expected_cash_paise' => null,
                'counted_cash_paise' => null,
                'difference_paise' => null,
                'summary' => null,
                'note' => null,
                'reopened_by' => $user->id,
                'reopened_at' => now(),
                'reopen_reason' => $reason,
            ])->save();
            Audit::log('day.reopened', $session, $before, ['adopted_payments' => $adopted], $reason);

            return $session->refresh();
        });
    }
}
