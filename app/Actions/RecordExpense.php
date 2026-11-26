<?php

namespace App\Actions;

use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Money spent on something other than stock: one expense row and its payment out. */
final class RecordExpense
{
    /** @param array{expense_category_id: int, amount_paise: int, method: string, expense_date?: ?string, description?: ?string, reference_no?: ?string} $data */
    public function handle(User $user, array $data): Expense
    {
        return DB::transaction(function () use ($user, $data) {
            $category = ExpenseCategory::where('store_id', $user->store_id)->where('is_active', true)->find($data['expense_category_id']);
            if ($category === null) {
                throw new BusinessRuleException('expense_category_missing', 'Choose an expense head.');
            }
            $date = $data['expense_date'] ?? ShopClock::todayString();
            $expense = Expense::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $user->store_id,
                'expense_category_id' => $category->id,
                'amount_paise' => $data['amount_paise'],
                'expense_date' => $date,
                'method' => $data['method'],
                'description' => $data['description'] ?? null,
                'reference_no' => $data['reference_no'] ?? null,
                'user_id' => $user->id,
            ])->refresh();

            Payment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $user->store_id,
                'direction' => 'out',
                'method' => $data['method'],
                'amount_paise' => $data['amount_paise'],
                'payable_type' => $expense->getMorphClass(),
                'payable_id' => $expense->id,
                'reference_no' => $data['reference_no'] ?? null,
                'business_date' => $date,
                'user_id' => $user->id,
                'note' => $category->name,
                'created_at' => now(),
            ]);
            Audit::log('expenses.recorded', $expense, new: ['amount_paise' => $expense->amount_paise, 'category' => $category->name, 'method' => $data['method']]);

            return $expense;
        });
    }
}
