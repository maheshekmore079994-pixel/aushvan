<?php

namespace App\Actions;

use App\Domain\Cash\DayBook;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\CashSession;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Freezes the open day: the figures as computed now, the counted cash and the difference are kept on the session. */
final class CloseDay
{
    public function __construct(private DayBook $book) {}

    public function handle(User $user, int $countedCashPaise, ?string $note = null): CashSession
    {
        return DB::transaction(function () use ($user, $countedCashPaise, $note) {
            $session = CashSession::where('store_id', $user->store_id)->where('status', CashSession::OPEN)->orderByDesc('business_date')->lockForUpdate()->first();
            if ($session === null) {
                throw new BusinessRuleException('day_not_open', 'No day is open.');
            }
            // Marked closed (in memory) first, so the figures run up to this moment and include nothing after it.
            $session->forceFill(['status' => CashSession::CLOSED, 'closed_by' => $user->id, 'closed_at' => now()]);
            $figures = $this->book->figures($session);
            $expected = $figures['expected_cash_paise'];

            $session->fill([
                'expected_cash_paise' => $expected,
                'counted_cash_paise' => $countedCashPaise,
                'difference_paise' => $countedCashPaise - $expected,
                'summary' => $figures,
                'note' => $note,
            ])->save();
            Audit::log('day.closed', $session, new: ['expected_cash_paise' => $expected, 'counted_cash_paise' => $countedCashPaise, 'difference_paise' => $countedCashPaise - $expected]);

            return $session->refresh();
        });
    }
}
