<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Cancels a bill made by mistake, on the same business day only. Everything the bill did is undone
 * by linked entries: stock goes back to the batches it came from, money taken is recorded as paid
 * back the same way, and any udhar it created is reversed. The bill keeps its number, marked cancelled.
 */
final class CancelSale
{
    public function __construct(private StockLedger $stock, private CustomerLedger $customers) {}

    public function handle(User $user, Sale $sale, string $reason): Sale
    {
        return DB::transaction(function () use ($user, $sale, $reason) {
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with(['items', 'payments'])->firstOrFail();

            if ($sale->status !== 'completed') {
                throw new BusinessRuleException('bill_cancelled', 'This bill is already cancelled.');
            }
            if ($sale->sale_date->toDateString() !== ShopClock::todayString()) {
                throw new BusinessRuleException('cancel_same_day_only', 'A bill can be cancelled only on the day it was made. Take the goods back as a return instead.');
            }
            if (SaleReturn::where('sale_id', $sale->id)->exists()) {
                throw new BusinessRuleException('has_returns', 'Goods have already been returned against this bill, so it cannot be cancelled.');
            }

            // Locks in the same order as CompleteSale and ReturnSale (customer, then batches) so two counters cannot deadlock.
            $customer = $sale->credit_paise > 0 ? Customer::withTrashed()->whereKey($sale->customer_id)->lockForUpdate()->firstOrFail() : null;

            foreach ($sale->items as $item) {
                $this->stock->record($item->batch()->lockForUpdate()->firstOrFail(), StockLedger::SELLABLE, $item->qty_base, 'sale_cancel', $sale, $user->id, $sale->invoice_no);
            }

            foreach ($sale->payments as $payment) {
                Payment::create([
                    'ulid' => (string) Str::ulid(),
                    'store_id' => $sale->store_id,
                    'direction' => 'out',
                    'method' => $payment->method,
                    'amount_paise' => $payment->amount_paise,
                    'party_type' => $payment->party_type,
                    'party_id' => $payment->party_id,
                    'payable_type' => $sale->getMorphClass(),
                    'payable_id' => $sale->id,
                    'reference_no' => $payment->reference_no,
                    'business_date' => ShopClock::todayString(),
                    'user_id' => $user->id,
                    'note' => 'cancelled '.$sale->invoice_no,
                    'created_at' => now(),
                ]);
            }

            if ($customer !== null) {
                $billEntry = CustomerLedgerEntry::where('source_type', $sale->getMorphClass())->where('source_id', $sale->id)->where('type', 'sale')->first();
                $this->customers->post($customer, 'reversal', 0, $sale->credit_paise, $sale, $user->id, 'cancelled '.$sale->invoice_no, $billEntry?->id);
            }

            $sale->update(['status' => 'cancelled', 'cancelled_by' => $user->id, 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            Audit::log('sales.cancelled', $sale, old: ['status' => 'completed'], new: ['status' => 'cancelled', 'total_paise' => $sale->total_paise], reason: $reason, userId: $user->id, storeId: $sale->store_id);

            return $sale->refresh();
        });
    }
}
