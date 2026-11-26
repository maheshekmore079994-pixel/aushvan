<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AdjustCustomerLedger;
use App\Actions\ReceiveCustomerPayment;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'owing' => ['nullable', 'boolean'],
        ]);
        $storeId = $request->user()->store_id;
        $query = Customer::where('store_id', $storeId)->where('is_active', true);

        foreach (preg_split('/\s+/u', mb_strtolower(trim((string) ($filters['search'] ?? ''))), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }
        if (! empty($filters['owing'])) {
            $query->where('balance_paise', '>', 0)->orderByDesc('balance_paise');
        } else {
            $query->orderBy('name');
        }

        $page = $query->paginate(25);
        $overdueDays = $this->overdueDays();

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $c) => $this->present($c, $overdueDays)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'total_owed_paise' => (int) Customer::where('store_id', $storeId)->where('balance_paise', '>', 0)->sum('balance_paise'),
            'customers_owing' => Customer::where('store_id', $storeId)->where('balance_paise', '>', 0)->count(),
        ]);
    }

    public function store(Request $request, CustomerLedger $ledger): JsonResponse
    {
        $data = $this->validated($request);
        $storeId = $request->user()->store_id;

        // Warn, do not block: two people can share a phone.
        if (! empty($data['mobile']) && empty($data['confirm_duplicate'])) {
            $same = Customer::where('store_id', $storeId)->where('mobile', $data['mobile'])->get(['id', 'name', 'village']);
            if ($same->isNotEmpty()) {
                return response()->json([
                    'code' => 'possible_duplicate', 'message' => 'A customer with this mobile number already exists.',
                    'duplicates' => $same,
                ], 409);
            }
        }

        $customer = DB::transaction(function () use ($data, $storeId, $request, $ledger) {
            $customer = Customer::create($this->attributes($data) + ['ulid' => (string) Str::ulid(), 'store_id' => $storeId])->refresh();
            // Udhar carried over from the paper book: the first line of the statement.
            $opening = (int) ($data['opening_balance_paise'] ?? 0);
            if ($opening !== 0) {
                $ledger->post($customer, 'opening', max($opening, 0), max(-$opening, 0), null, $request->user()->id, 'Opening balance');
            }
            Audit::log('customers.created', $customer, new: ['opening_balance_paise' => $opening]);

            return $customer;
        });

        return response()->json($this->present($customer->refresh()), 201);
    }

    public function update(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($customer->store_id === $request->user()->store_id, 404);
        $before = $customer->only(['name', 'mobile', 'credit_limit_paise']);
        $customer->update($this->attributes($this->validated($request)));
        Audit::log('customers.updated', $customer, $before, $customer->only(['name', 'mobile', 'credit_limit_paise']));

        return response()->json($this->present($customer->refresh()));
    }

    /** The customer with their statement, newest entry first. */
    public function show(Request $request, Customer $customer): JsonResponse
    {
        abort_unless($customer->store_id === $request->user()->store_id, 404);

        $entries = $customer->ledger()->orderByDesc('id')->limit(200)->get();
        // What may be undone from this screen: payments on account, openings and adjustments that were not undone yet.
        // Money taken on a bill is corrected by cancelling the bill.
        $paymentType = (new Payment)->getMorphClass();
        $billPayments = Payment::whereIn('id', $entries->where('source_type', $paymentType)->pluck('source_id'))->whereNotNull('payable_type')->pluck('id')->all();
        $reversed = CustomerLedgerEntry::where('customer_id', $customer->id)->whereNotNull('reverses_id')->pluck('reverses_id')->all();
        $reversible = fn (CustomerLedgerEntry $e) => in_array($e->type, ['payment', 'opening', 'adjustment'], true)
            && ! in_array($e->id, $reversed, true)
            && ! ($e->source_type === $paymentType && in_array($e->source_id, $billPayments, true));

        return response()->json($this->present($customer) + [
            'entries' => $entries->map(fn (CustomerLedgerEntry $e) => [
                'reversible' => $reversible($e),
                'id' => $e->id,
                'date' => $e->entry_date->toDateString(),
                'type' => $e->type,
                'debit_paise' => $e->debit_paise,
                'credit_paise' => $e->credit_paise,
                'balance_after_paise' => $e->balance_after_paise,
                'note' => $e->note,
                'sale_id' => $e->source_type === (new Sale)->getMorphClass() ? $e->source_id : null,
            ]),
        ]);
    }

    public function receivePayment(Request $request, Customer $customer, ReceiveCustomerPayment $action): JsonResponse
    {
        abort_unless($customer->store_id === $request->user()->store_id, 404);

        $data = $request->validate([
            'amount_paise' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:300'],
        ]);

        $action->handle($request->user(), $customer, $data['amount_paise'], $data['method'], $data['reference_no'] ?? null, $data['note'] ?? null);

        return response()->json($this->present($customer->refresh()), 201);
    }

    /** Undoes one earlier payment / opening / adjustment entry in full (credit.reverse). */
    public function reverse(Request $request, Customer $customer, CustomerLedgerEntry $entry, AdjustCustomerLedger $action): JsonResponse
    {
        abort_unless($customer->store_id === $request->user()->store_id && $entry->customer_id === $customer->id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);
        $action->reverse($request->user(), $customer, $entry, trim($data['reason']));

        return response()->json($this->present($customer->refresh()), 201);
    }

    /** Moves the balance by a stated amount with a reason: a write-off ('down') or a correction ('up'). */
    public function adjust(Request $request, Customer $customer, AdjustCustomerLedger $action): JsonResponse
    {
        abort_unless($customer->store_id === $request->user()->store_id, 404);
        $data = $request->validate([
            'direction' => ['required', Rule::in(['up', 'down'])],
            'amount_paise' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
        ]);
        $action->adjust($request->user(), $customer, $data['direction'], $data['amount_paise'], trim($data['reason']));

        return response()->json($this->present($customer->refresh()), 201);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'mobile' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'village' => ['nullable', 'string', 'max:100'],
            'address' => ['nullable', 'string', 'max:300'],
            'credit_limit_paise' => ['nullable', 'integer', 'min:0', 'max:1000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            // Udhar from the paper book when the customer is first added (negative = an advance the shop holds).
            'opening_balance_paise' => [$request->isMethod('POST') ? 'nullable' : 'prohibited', 'integer', 'min:-1000000000', 'max:1000000000'],
            'confirm_duplicate' => ['boolean'],
        ]);

        // Only users who may override prices may set or change an udhar limit.
        if (! $request->user()->can(Permission::SALES_PRICE_OVERRIDE)) {
            unset($data['credit_limit_paise']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function attributes(array $data): array
    {
        $attributes = [
            'name' => trim($data['name']),
            'mobile' => $data['mobile'] ?? null,
            'village' => $data['village'] ?? null,
            'address' => $data['address'] ?? null,
            'notes' => $data['notes'] ?? null,
            'search_text' => Product::buildSearchText($data['name'], $data['mobile'] ?? null, $data['village'] ?? null),
        ];
        if (array_key_exists('credit_limit_paise', $data)) {
            $attributes['credit_limit_paise'] = (int) $data['credit_limit_paise'];
        }

        return $attributes;
    }

    /** @return array<string, mixed> */
    private function present(Customer $customer, ?int $overdueDays = null): array
    {
        $overdueDays ??= $this->overdueDays();

        return [
            'id' => $customer->id,
            'name' => $customer->name,
            'mobile' => $customer->mobile,
            'village' => $customer->village,
            'address' => $customer->address,
            'notes' => $customer->notes,
            'credit_limit_paise' => $customer->credit_limit_paise,
            'balance_paise' => $customer->balance_paise,
            'last_payment_at' => $customer->last_payment_at?->toIso8601String(),
            'days_since_payment' => $days = (int) ShopClock::today()->startOfDay()->diffInDays(($customer->last_payment_at ?? $customer->created_at)->copy()->timezone(config('shop.timezone'))->startOfDay(), true),
            // Owing and nothing paid for longer than the shop's limit (setting udhar_overdue_days, 30 by default).
            'overdue' => $customer->balance_paise > 0 && $days > $overdueDays,
        ];
    }

    /** The shop's own limit for "overdue" (setting udhar_overdue_days), read fresh on every request. */
    private function overdueDays(): int
    {
        return (int) (request()->user()->store->setting('udhar_overdue_days', 30) ?: 30);
    }
}
