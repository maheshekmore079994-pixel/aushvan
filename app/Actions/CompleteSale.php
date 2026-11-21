<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Sales\SalePricer;
use App\Domain\Shared\Audit;
use App\Domain\Shared\NumberSequencer;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\Sale;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Turns a cart into a bill. In one transaction it: prices the cart again,
 * checks payments, udhar and prescription rules, issues the bill number,
 * takes the stock out batch by batch, records the money received and posts
 * any unpaid amount to the customer's udhar account.
 *
 * The cart's own id (ulid) makes it safe to repeat: sending the same cart
 * again returns the bill already made instead of making a second one.
 */
final class CompleteSale
{
    /** True when the last call returned a bill that already existed. */
    public bool $wasReplay = false;

    public function __construct(
        private SalePricer $pricer,
        private StockLedger $stock,
        private CustomerLedger $customers,
        private NumberSequencer $numbers,
    ) {}

    /** Wrong PINs allowed for one pharmacist before PIN approval is locked for a while. */
    public const PIN_ATTEMPTS = 5;

    public const PIN_LOCK_SECONDS = 900;

    private ?int $wrongPinFor = null;

    /** @param array<string, mixed> $data validated by the controller */
    public function handle(User $user, array $data): Sale
    {
        $this->wasReplay = false;

        $this->wrongPinFor = null;

        try {
            return DB::transaction(fn () => $this->complete($user, $data));
        } catch (BusinessRuleException $e) {
            // Counted after the transaction has rolled back, so the count itself is not rolled back with it.
            if ($this->wrongPinFor !== null) {
                RateLimiter::hit(self::pinKey($this->wrongPinFor), self::PIN_LOCK_SECONDS);
                Audit::log('pharmacist.pin_failed', null, new: ['pharmacist_id' => $this->wrongPinFor], userId: $user->id, storeId: $user->store_id);
            }
            throw $e;
        } catch (UniqueConstraintViolationException $e) {
            // Two copies of the same request raced; the first one made the bill.
            $existing = Sale::where('store_id', $user->store_id)->where('ulid', $data['ulid'])->first();
            if ($existing === null) {
                // The id belongs to a bill of another shop. Never show it; the screen starts a new bill id.
                throw new BusinessRuleException('cart_conflict', 'This bill could not be saved. Clear the bill and enter it again.');
            }
            $this->wasReplay = true;

            return $existing;
        }
    }

    /** @param array<string, mixed> $data */
    private function complete(User $user, array $data): Sale
    {
        $existing = Sale::where('store_id', $user->store_id)->where('ulid', $data['ulid'])->first();
        if ($existing !== null) {
            $this->wasReplay = true;

            return $existing;
        }

        $store = $user->store;
        $priced = $this->pricer->price($store, $data['lines']);

        if ($priced['errors'] !== []) {
            $first = $priced['errors'][0];
            throw new BusinessRuleException($first['code'], $this->lineMessage($first), ['line_errors' => $priced['errors']]);
        }

        $total = $priced['totals']['total_paise'];
        $paid = array_sum(array_column($data['payments'] ?? [], 'amount_paise'));
        if ($paid > $total) {
            throw new BusinessRuleException('overpaid', 'Payments are more than the bill total.', ['total_paise' => $total]);
        }
        $credit = $total - $paid;

        $customer = isset($data['customer_id'])
            // Locked so two udhar bills for one customer at the same moment cannot both pass the limit check.
            ? Customer::where('store_id', $store->id)->where('is_active', true)->lockForUpdate()->findOrFail($data['customer_id'])
            : null;

        if ($credit > 0) {
            if ($customer === null) {
                throw new BusinessRuleException('customer_required', 'Choose a customer to give udhar.');
            }
            $limit = $customer->credit_limit_paise;
            if ($limit > 0 && $customer->balance_paise + $credit > $limit
                && ! (! empty($data['override_credit_limit']) && $user->can(Permission::SALES_PRICE_OVERRIDE))) {
                throw new BusinessRuleException('credit_limit_exceeded', 'This would take the customer over their udhar limit.', [
                    'limit_paise' => $limit, 'balance_paise' => $customer->balance_paise,
                ]);
            }
        }

        $prescription = $this->prescription($user, $data, $priced['requires']['prescription'], $customer);
        $pharmacistId = $this->pharmacist($user, $data, $priced['requires']['pharmacist']);

        $sale = Sale::create($priced['totals'] + [
            'ulid' => $data['ulid'],
            'store_id' => $store->id,
            'invoice_no' => $this->numbers->next($store->id, 'sale', (string) $store->setting('counter_prefix', '')),
            'doc_kind' => $priced['doc_kind'],
            'sale_date' => ShopClock::todayString(),
            'completed_at' => now(),
            'customer_id' => $customer?->id,
            'buyer_name' => $customer?->name,
            'paid_paise' => $paid,
            'credit_paise' => $credit,
            'prescription_id' => $prescription?->id,
            'verified_by_pharmacist_id' => $pharmacistId,
            'user_id' => $user->id,
            'notes' => $data['notes'] ?? null,
        ]);

        foreach ($priced['lines'] as $number => $line) {
            unset($line['index']);
            $sale->items()->create($line + ['line_no' => $number + 1]);

            // Refuses, and rolls the whole bill back, if another counter took the stock first.
            $this->stock->record($priced['batches'][$line['batch_id']], StockLedger::SELLABLE, -$line['qty_base'], 'sale', $sale, $user->id);
        }

        foreach ($data['payments'] ?? [] as $payment) {
            Payment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $store->id,
                'direction' => 'in',
                'method' => $payment['method'],
                'amount_paise' => $payment['amount_paise'],
                'party_type' => $customer?->getMorphClass(),
                'party_id' => $customer?->id,
                'payable_type' => $sale->getMorphClass(),
                'payable_id' => $sale->id,
                'reference_no' => $payment['reference_no'] ?? null,
                'business_date' => $sale->sale_date,
                'user_id' => $user->id,
                'created_at' => now(),
            ]);
        }

        if ($credit > 0) {
            $this->customers->post($customer, 'sale', $total, 0, $sale, $user->id, $sale->invoice_no);
            if ($paid > 0) {
                $this->customers->post($customer, 'payment', 0, $paid, $sale, $user->id, $sale->invoice_no);
            }
            if (! empty($data['override_credit_limit'])) {
                Audit::log('sales.credit_limit_overridden', $sale, new: ['customer_id' => $customer->id, 'credit_paise' => $credit]);
            }
        }

        return $sale->refresh();
    }

    /** @param array<string, mixed> $data */
    private function prescription(User $user, array $data, bool $required, ?Customer $customer): ?Prescription
    {
        $given = $data['prescription'] ?? null;
        $complete = is_array($given) && trim((string) ($given['prescriber_name'] ?? '')) !== '' && trim((string) ($given['patient_name'] ?? '')) !== '';

        if (! $complete) {
            if ($required) {
                throw new BusinessRuleException('prescription_required', 'This bill has prescription medicine. Enter the doctor and patient details.');
            }

            return null;
        }

        return Prescription::create([
            'store_id' => $user->store_id,
            'customer_id' => $customer?->id,
            'patient_name' => trim($given['patient_name']),
            'patient_address' => $given['patient_address'] ?? null,
            'prescriber_name' => trim($given['prescriber_name']),
            'prescriber_address' => $given['prescriber_address'] ?? null,
            'prescriber_reg_no' => $given['prescriber_reg_no'] ?? null,
            'prescription_date' => $given['prescription_date'] ?? null,
            'created_by' => $user->id,
        ]);
    }

    /**
     * Who took responsibility for a sale that needs a pharmacist: the signed-in
     * user if they are one, otherwise a pharmacist who entered their PIN.
     *
     * @param  array<string, mixed>  $data
     */
    private function pharmacist(User $user, array $data, bool $required): ?int
    {
        if (! $required) {
            return null;
        }
        if ($user->is_pharmacist) {
            return $user->id;
        }

        $pharmacist = User::where('store_id', $user->store_id)->where('is_active', true)->where('is_pharmacist', true)
            ->find($data['pharmacist']['user_id'] ?? 0);
        $pin = (string) ($data['pharmacist']['pin'] ?? '');

        if ($pharmacist !== null && RateLimiter::tooManyAttempts(self::pinKey($pharmacist->id), self::PIN_ATTEMPTS)) {
            throw new BusinessRuleException('pin_locked', 'Too many wrong PINs. Wait 15 minutes, or let the pharmacist sign in and make the bill.');
        }

        if ($pharmacist === null || $pharmacist->pin === null || $pin === '' || ! Hash::check($pin, $pharmacist->pin)) {
            if ($pharmacist !== null && $pin !== '') {
                $this->wrongPinFor = $pharmacist->id;
            }
            throw new BusinessRuleException('pharmacist_required', 'A registered pharmacist must approve this bill with their PIN.');
        }

        RateLimiter::clear(self::pinKey($pharmacist->id));

        return $pharmacist->id;
    }

    private static function pinKey(int $pharmacistId): string
    {
        return 'pharmacist-pin:'.$pharmacistId;
    }

    /** @param array<string, mixed> $error */
    private function lineMessage(array $error): string
    {
        $name = $error['product'] ?? 'This item';

        return match ($error['code']) {
            'insufficient_stock' => "{$name}: not enough stock.",
            'batch_expired' => "{$name}: this batch has expired and cannot be sold.",
            'loose_not_allowed' => "{$name} cannot be sold loose.",
            default => "{$name} cannot be sold.",
        };
    }
}
