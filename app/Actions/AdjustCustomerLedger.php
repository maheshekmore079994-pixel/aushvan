<?php

namespace App\Actions;

use App\Domain\Parties\CustomerLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Corrections to a customer's udhar account, never edits (J3): a **reversal** undoes one earlier entry in full
 * (a payment typed against the wrong customer, an opening balance entered twice), an **adjustment** moves the
 * balance by a stated amount with a reason (a write-off, a settlement). Both need credit.reverse and are logged.
 *
 * Reversing a payment also mirrors its money row out, so the day's cash and the collection reports net to
 * zero and the payment can be received again on the right customer without counting twice.
 */
final class AdjustCustomerLedger
{
    public function __construct(private CustomerLedger $ledger) {}

    public function reverse(User $user, Customer $customer, CustomerLedgerEntry $entry, string $reason): CustomerLedgerEntry
    {
        try {
            return DB::transaction(function () use ($user, $customer, $entry, $reason) {
                $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
                if ($entry->customer_id !== $customer->id) {
                    throw new BusinessRuleException('entry_not_found', 'This entry does not belong to the customer.');
                }
                if (in_array($entry->type, ['sale', 'return', 'reversal'], true)) {
                    // Bills and credit notes are reversed through cancellation and returns, which also put the stock right.
                    throw new BusinessRuleException('entry_not_reversible', 'Cancel the bill or make a return instead.');
                }
                if (CustomerLedgerEntry::where('reverses_id', $entry->id)->exists()) {
                    throw new BusinessRuleException('entry_already_reversed', 'This entry was already reversed.');
                }

                $payment = $entry->source_type === (new Payment)->getMorphClass() ? Payment::find($entry->source_id) : null;
                if ($payment !== null && $payment->payable_type !== null) {
                    // Money taken on a bill belongs to that bill: cancelling the bill puts it right, together with the stock.
                    throw new BusinessRuleException('entry_not_reversible', 'This payment was taken on a bill; cancel the bill instead.');
                }
                $mirror = null;
                if ($payment !== null) {
                    $mirror = Payment::create([
                        'ulid' => (string) Str::ulid(),
                        'store_id' => $customer->store_id,
                        'direction' => $payment->direction === 'in' ? 'out' : 'in',
                        'method' => $payment->method,
                        'amount_paise' => $payment->amount_paise,
                        'party_type' => $customer->getMorphClass(),
                        'party_id' => $customer->id,
                        'reference_no' => $payment->reference_no,
                        'business_date' => ShopClock::todayString(),
                        'user_id' => $user->id,
                        'note' => $reason,
                        'created_at' => now(),
                    ]);
                }

                $reversal = $this->ledger->post($customer, 'reversal', $entry->credit_paise, $entry->debit_paise, $mirror, $user->id, $reason, $entry->id);

                if ($entry->type === 'payment') {
                    // "Last paid" must not point at a payment that was never theirs.
                    $reversed = CustomerLedgerEntry::where('customer_id', $customer->id)->where('type', 'reversal')->whereNotNull('reverses_id')->pluck('reverses_id');
                    $latest = CustomerLedgerEntry::where('customer_id', $customer->id)->where('type', 'payment')->whereNotIn('id', $reversed)->orderByDesc('id')->first();
                    Customer::withTrashed()->whereKey($customer->id)->update(['last_payment_at' => $latest?->created_at]);
                }
                Audit::log('credit.reversed', $customer, new: ['entry_id' => $entry->id, 'type' => $entry->type, 'debit_paise' => $entry->debit_paise, 'credit_paise' => $entry->credit_paise, 'mirrored_payment_id' => $mirror?->id], reason: $reason);

                return $reversal;
            });
        } catch (UniqueConstraintViolationException) {
            // Two reversals of the same entry raced; the unique index on reverses_id let only one through.
            throw new BusinessRuleException('entry_already_reversed', 'This entry was already reversed.');
        }
    }

    /** direction 'up' = the customer owes more; 'down' = owes less (a write-off). */
    public function adjust(User $user, Customer $customer, string $direction, int $amountPaise, string $reason): CustomerLedgerEntry
    {
        return DB::transaction(function () use ($user, $customer, $direction, $amountPaise, $reason) {
            $customer = Customer::whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $entry = $direction === 'up'
                ? $this->ledger->post($customer, 'adjustment', $amountPaise, 0, null, $user->id, $reason)
                : $this->ledger->post($customer, 'adjustment', 0, $amountPaise, null, $user->id, $reason);
            Audit::log('credit.adjusted', $customer, new: ['direction' => $direction, 'amount_paise' => $amountPaise], reason: $reason);

            return $entry;
        });
    }
}
