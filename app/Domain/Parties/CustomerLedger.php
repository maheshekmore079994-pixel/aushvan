<?php

namespace App\Domain\Parties;

use App\Domain\Shared\ShopClock;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;

/**
 * The only code allowed to change what a customer owes. Each call writes
 * one ledger line and moves the customer's balance by the same amount, so
 * the last line of the statement always equals the balance on the card.
 */
final class CustomerLedger
{
    /** debit = customer owes more (a bill); credit = customer owes less (a payment or return). */
    public function post(
        Customer $customer,
        string $type,
        int $debitPaise,
        int $creditPaise,
        ?Model $source,
        int $userId,
        ?string $note = null,
        ?int $reversesId = null,
    ): CustomerLedgerEntry {
        if (DB::transactionLevel() === 0) {
            throw new LogicException('The customer ledger may only be changed inside a database transaction.');
        }
        if ($debitPaise < 0 || $creditPaise < 0 || ($debitPaise === 0 && $creditPaise === 0)) {
            throw new LogicException('A ledger entry needs a positive debit or credit.');
        }

        // A customer removed from the list may still owe or be owed (an old bill cancelled today), so the row is found
        // even when soft-deleted, and a balance that did not move is an error, never a silent no-op.
        $moved = Customer::withTrashed()->whereKey($customer->id)->increment('balance_paise', $debitPaise - $creditPaise);
        if ($moved !== 1) {
            throw new LogicException('Customer balance could not be updated.');
        }
        if ($type === 'payment') {
            Customer::withTrashed()->whereKey($customer->id)->update(['last_payment_at' => now()]);
        }
        $customer->refresh();

        return CustomerLedgerEntry::create([
            'ulid' => (string) Str::ulid(),
            'store_id' => $customer->store_id,
            'customer_id' => $customer->id,
            'entry_date' => ShopClock::todayString(),
            'type' => $type,
            'debit_paise' => $debitPaise,
            'credit_paise' => $creditPaise,
            'balance_after_paise' => $customer->balance_paise,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'reverses_id' => $reversesId,
            'note' => $note,
            'user_id' => $userId,
            'created_at' => now(),
        ]);
    }

    /**
     * Customers whose balance does not equal the total of their ledger. Empty = healthy.
     *
     * @return list<array{customer_id: int, balance: int, ledger: int}>
     */
    public function findMismatches(): array
    {
        $totals = DB::table('customer_ledger_entries')
            ->selectRaw('customer_id, SUM(debit_paise) - SUM(credit_paise) as total')
            ->groupBy('customer_id')
            ->pluck('total', 'customer_id');

        $problems = [];
        foreach (Customer::withTrashed()->get(['id', 'balance_paise']) as $customer) {
            $ledger = (int) ($totals[$customer->id] ?? 0);
            if ($ledger !== $customer->balance_paise) {
                $problems[] = ['customer_id' => $customer->id, 'balance' => $customer->balance_paise, 'ledger' => $ledger];
            }
        }

        return $problems;
    }
}
