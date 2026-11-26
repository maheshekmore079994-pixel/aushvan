<?php

namespace App\Actions;

use App\Domain\Parties\SupplierLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Money paid to a supplier: against one purchase, or on account (the oldest dues first is the supplier's business). */
final class PaySupplier
{
    public function __construct(private SupplierLedger $ledger) {}

    public function handle(User $user, Supplier $supplier, int $amountPaise, string $method, ?string $reference = null, ?string $note = null, ?Purchase $purchase = null): Payment
    {
        return DB::transaction(function () use ($user, $supplier, $amountPaise, $method, $reference, $note, $purchase) {
            if ($purchase !== null) {
                $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
                if ($purchase->supplier_id !== $supplier->id || $purchase->status !== Purchase::CONFIRMED) {
                    throw new BusinessRuleException('purchase_not_payable', 'This purchase cannot take a payment.');
                }
                if ($purchase->paid_paise + $amountPaise > $purchase->total_paise) {
                    throw new BusinessRuleException('overpaid', 'The payment is more than what is left on this purchase.', [
                        'due_paise' => $purchase->total_paise - $purchase->paid_paise,
                    ]);
                }
            }

            $payment = Payment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $supplier->store_id,
                'direction' => 'out',
                'method' => $method,
                'amount_paise' => $amountPaise,
                'party_type' => $supplier->getMorphClass(),
                'party_id' => $supplier->id,
                'payable_type' => $purchase?->getMorphClass(),
                'payable_id' => $purchase?->id,
                'reference_no' => $reference,
                'business_date' => ShopClock::todayString(),
                'user_id' => $user->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->ledger->post($supplier, 'payment', 0, $amountPaise, $payment, $user->id, $note ?? $purchase?->purchase_no);
            $purchase?->update(['paid_paise' => $purchase->paid_paise + $amountPaise]);
            Audit::log('suppliers.paid', $supplier, new: ['amount_paise' => $amountPaise, 'method' => $method, 'purchase_id' => $purchase?->id]);

            return $payment;
        });
    }
}
