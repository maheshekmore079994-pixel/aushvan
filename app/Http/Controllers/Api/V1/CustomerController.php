<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReceiveCustomerPayment;
use App\Domain\Shared\Audit;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        return response()->json([
            'data' => collect($page->items())->map(fn (Customer $c) => $this->present($c)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'total_owed_paise' => (int) Customer::where('store_id', $storeId)->where('balance_paise', '>', 0)->sum('balance_paise'),
            'customers_owing' => Customer::where('store_id', $storeId)->where('balance_paise', '>', 0)->count(),
        ]);
    }

    public function store(Request $request): JsonResponse
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

        $customer = Customer::create($this->attributes($data) + ['ulid' => (string) Str::ulid(), 'store_id' => $storeId]);
        Audit::log('customers.created', $customer);

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

        return response()->json($this->present($customer) + [
            'entries' => $entries->map(fn (CustomerLedgerEntry $e) => [
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
    private function present(Customer $customer): array
    {
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
        ];
    }
}
