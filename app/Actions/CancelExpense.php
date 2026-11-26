<?php

namespace App\Actions;

use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Expense;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** An expense entered wrongly is cancelled with a reason; its payment is mirrored back in. The row stays. */
final class CancelExpense
{
    public function handle(User $user, Expense $expense, string $reason): Expense
    {
        return DB::transaction(function () use ($user, $expense, $reason) {
            $expense = Expense::whereKey($expense->id)->lockForUpdate()->firstOrFail();
            if ($expense->isCancelled()) {
                throw new BusinessRuleException('expense_cancelled', 'This expense is already cancelled.');
            }
            Payment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $expense->store_id,
                'direction' => 'in',
                'method' => $expense->method,
                'amount_paise' => $expense->amount_paise,
                'payable_type' => $expense->getMorphClass(),
                'payable_id' => $expense->id,
                'reference_no' => $expense->reference_no,
                'business_date' => ShopClock::todayString(),
                'user_id' => $user->id,
                'note' => $reason,
                'created_at' => now(),
            ]);
            $expense->fill(['cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason])->save();
            Audit::log('expenses.cancelled', $expense, reason: $reason);

            return $expense->refresh();
        });
    }
}
