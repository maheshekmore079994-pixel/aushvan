<?php

namespace App\Actions;

use App\Domain\Parties\CustomerLedger;
use App\Domain\Shared\ShopClock;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/** Money received from a customer against their udhar (or as an advance if they owe nothing). */
final class ReceiveCustomerPayment
{
    public function __construct(private CustomerLedger $ledger) {}

    public function handle(User $user, Customer $customer, int $amountPaise, string $method, ?string $reference = null, ?string $note = null): Payment
    {
        return DB::transaction(function () use ($user, $customer, $amountPaise, $method, $reference, $note) {
            $payment = Payment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $customer->store_id,
                'direction' => 'in',
                'method' => $method,
                'amount_paise' => $amountPaise,
                'party_type' => $customer->getMorphClass(),
                'party_id' => $customer->id,
                'reference_no' => $reference,
                'business_date' => ShopClock::todayString(),
                'user_id' => $user->id,
                'note' => $note,
                'created_at' => now(),
            ]);

            $this->ledger->post($customer, 'payment', 0, $amountPaise, $payment, $user->id, $note);

            return $payment;
        });
    }
}
