<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\PaySupplier;
use App\Domain\Parties\SupplierLedger;
use App\Domain\Shared\Audit;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\Supplier;
use App\Models\SupplierContact;
use App\Models\SupplierLedgerEntry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class SupplierController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'owing' => ['nullable', 'boolean'],
        ]);
        $storeId = $request->user()->store_id;
        $query = Supplier::where('store_id', $storeId)->where('is_active', true);

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
            'data' => collect($page->items())->map(fn (Supplier $s) => $this->present($s)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'total_owed_paise' => (int) Supplier::where('store_id', $storeId)->where('balance_paise', '>', 0)->sum('balance_paise'),
            'suppliers_owed' => Supplier::where('store_id', $storeId)->where('balance_paise', '>', 0)->count(),
        ]);
    }

    public function store(Request $request, SupplierLedger $ledger): JsonResponse
    {
        $data = $this->validated($request);
        $storeId = $request->user()->store_id;

        if (empty($data['confirm_duplicate'])) {
            $same = Supplier::where('store_id', $storeId)
                ->where(fn ($q) => $q->whereRaw('LOWER(name) = ?', [mb_strtolower(trim($data['name']))])
                    ->when(! empty($data['gstin']), fn ($q) => $q->orWhere('gstin', $data['gstin'])))
                ->get(['id', 'name', 'mobile']);
            if ($same->isNotEmpty()) {
                return response()->json([
                    'code' => 'possible_duplicate', 'message' => 'A supplier with this name or GSTIN already exists.', 'duplicates' => $same,
                ], 409);
            }
        }

        $supplier = DB::transaction(function () use ($data, $storeId, $request, $ledger) {
            $supplier = Supplier::create($this->attributes($data) + ['ulid' => (string) Str::ulid(), 'store_id' => $storeId])->refresh();
            $opening = (int) ($data['opening_balance_paise'] ?? 0);
            if ($opening > 0) {
                $ledger->post($supplier, 'opening', $opening, 0, null, $request->user()->id, 'Opening balance');
            }
            Audit::log('suppliers.created', $supplier, new: ['opening_balance_paise' => $opening]);

            return $supplier;
        });

        return response()->json($this->present($supplier->refresh()), 201);
    }

    public function update(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id, 404);
        $before = $supplier->only(['name', 'mobile', 'gstin', 'payment_terms_days']);
        $supplier->update($this->attributes($this->validated($request)));
        Audit::log('suppliers.updated', $supplier, $before, $supplier->only(['name', 'mobile', 'gstin', 'payment_terms_days']));

        return response()->json($this->present($supplier->refresh()));
    }

    /** The supplier with contacts, statement (newest first) and recent purchases. */
    public function show(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id, 404);

        $purchaseType = (new Purchase)->getMorphClass();
        $returnType = (new PurchaseReturn)->getMorphClass();
        $entries = $supplier->ledger()->orderByDesc('id')->limit(200)->get();
        $purchases = $supplier->purchases()->where('status', '!=', Purchase::DRAFT)->orderByDesc('id')->limit(50)->get();

        return response()->json($this->present($supplier) + [
            'contacts' => $supplier->contacts()->where('is_active', true)->orderBy('name')->get()->map(fn (SupplierContact $c) => $this->presentContact($c)),
            'entries' => $entries->map(fn (SupplierLedgerEntry $e) => [
                'id' => $e->id,
                'date' => $e->entry_date->toDateString(),
                'type' => $e->type,
                'credit_paise' => $e->credit_paise,
                'debit_paise' => $e->debit_paise,
                'balance_after_paise' => $e->balance_after_paise,
                'note' => $e->note,
                'purchase_id' => $e->source_type === $purchaseType ? $e->source_id : null,
                'return_id' => $e->source_type === $returnType ? $e->source_id : null,
            ]),
            'purchases' => $purchases->map(fn (Purchase $p) => [
                'id' => $p->id,
                'purchase_no' => $p->purchase_no,
                'supplier_invoice_no' => $p->supplier_invoice_no,
                'received_date' => $p->received_date->toDateString(),
                'status' => $p->status,
                'total_paise' => $p->total_paise,
                'paid_paise' => $p->paid_paise,
                'due_date' => $p->due_date?->toDateString(),
            ]),
        ]);
    }

    public function pay(Request $request, Supplier $supplier, PaySupplier $action): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id, 404);
        $data = $request->validate([
            'amount_paise' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'method' => ['required', Rule::in(Payment::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:50'],
            'note' => ['nullable', 'string', 'max:300'],
            'purchase_id' => ['nullable', 'integer'],
        ]);
        $purchase = isset($data['purchase_id']) ? Purchase::where('store_id', $supplier->store_id)->findOrFail($data['purchase_id']) : null;

        $action->handle($request->user(), $supplier, $data['amount_paise'], $data['method'], $data['reference_no'] ?? null, $data['note'] ?? null, $purchase);

        return response()->json($this->present($supplier->refresh()), 201);
    }

    public function storeContact(Request $request, Supplier $supplier): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id, 404);
        $contact = $supplier->contacts()->create($this->contactAttributes($request) + ['store_id' => $supplier->store_id]);
        Audit::log('suppliers.contact_added', $supplier, new: ['contact_id' => $contact->id, 'name' => $contact->name]);

        return response()->json($this->presentContact($contact->refresh()), 201);
    }

    public function updateContact(Request $request, Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id && $contact->supplier_id === $supplier->id, 404);
        $contact->update($this->contactAttributes($request));

        return response()->json($this->presentContact($contact->refresh()));
    }

    public function removeContact(Request $request, Supplier $supplier, SupplierContact $contact): JsonResponse
    {
        abort_unless($supplier->store_id === $request->user()->store_id && $contact->supplier_id === $supplier->id, 404);
        $contact->update(['is_active' => false]);
        Audit::log('suppliers.contact_removed', $supplier, new: ['contact_id' => $contact->id, 'name' => $contact->name]);

        return response()->json(null, 204);
    }

    /** Everyone's visiting MRs for the week, grouped by day: a shop plans orders around them. */
    public function visits(Request $request): JsonResponse
    {
        $contacts = SupplierContact::with('supplier:id,name')
            ->where('store_id', $request->user()->store_id)->where('is_active', true)->whereNotNull('visit_day')
            ->orderBy('visit_day')->orderBy('name')->get();

        return response()->json(['data' => $contacts->map(fn (SupplierContact $c) => $this->presentContact($c) + ['supplier' => ['id' => $c->supplier_id, 'name' => $c->supplier->name]])]);
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'name' => ['required', 'string', 'max:150'],
            'kind' => ['nullable', Rule::in(Supplier::KINDS)],
            'contact_person' => ['nullable', 'string', 'max:100'],
            'mobile' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'address' => ['nullable', 'string', 'max:300'],
            'gstin' => ['nullable', 'regex:/^[0-9]{2}[A-Za-z0-9]{13}$/'],
            'drug_licence_no' => ['nullable', 'string', 'max:50'],
            'payment_terms_days' => ['nullable', 'integer', 'min:0', 'max:365'],
            'notes' => ['nullable', 'string', 'max:500'],
            // An opening balance is only given when the supplier is first added; later it is a ledger matter.
            'opening_balance_paise' => [$request->isMethod('POST') ? 'nullable' : 'prohibited', 'integer', 'min:0', 'max:100000000000'],
            'confirm_duplicate' => ['boolean'],
        ]);
    }

    /** @return array<string, mixed> */
    private function attributes(array $data): array
    {
        return [
            'name' => trim($data['name']),
            'kind' => $data['kind'] ?? 'distributor',
            'contact_person' => $data['contact_person'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'address' => $data['address'] ?? null,
            'gstin' => isset($data['gstin']) ? strtoupper($data['gstin']) : null,
            'drug_licence_no' => $data['drug_licence_no'] ?? null,
            'payment_terms_days' => (int) ($data['payment_terms_days'] ?? 0),
            'notes' => $data['notes'] ?? null,
            'search_text' => Product::buildSearchText($data['name'], $data['mobile'] ?? null, $data['contact_person'] ?? null, $data['gstin'] ?? null),
        ];
    }

    /** @return array<string, mixed> */
    private function contactAttributes(Request $request): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'role' => ['nullable', Rule::in(SupplierContact::ROLES)],
            'company' => ['nullable', 'string', 'max:150'],
            'mobile' => ['nullable', 'regex:/^[0-9]{10}$/'],
            'visit_day' => ['nullable', 'integer', 'min:0', 'max:6'],
            'schemes' => ['nullable', 'string', 'max:500'],
            'notes' => ['nullable', 'string', 'max:300'],
        ]);

        return [
            'name' => trim($data['name']),
            'role' => $data['role'] ?? 'mr',
            'company' => $data['company'] ?? null,
            'mobile' => $data['mobile'] ?? null,
            'visit_day' => $data['visit_day'] ?? null,
            'schemes' => $data['schemes'] ?? null,
            'notes' => $data['notes'] ?? null,
        ];
    }

    /** @return array<string, mixed> */
    private function present(Supplier $s): array
    {
        return [
            'id' => $s->id,
            'name' => $s->name,
            'kind' => $s->kind,
            'contact_person' => $s->contact_person,
            'mobile' => $s->mobile,
            'address' => $s->address,
            'gstin' => $s->gstin,
            'drug_licence_no' => $s->drug_licence_no,
            'payment_terms_days' => $s->payment_terms_days,
            'notes' => $s->notes,
            'balance_paise' => $s->balance_paise,
            'last_payment_at' => $s->last_payment_at?->toIso8601String(),
        ];
    }

    /** @return array<string, mixed> */
    private function presentContact(SupplierContact $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'role' => $c->role,
            'company' => $c->company,
            'mobile' => $c->mobile,
            'visit_day' => $c->visit_day,
            'schemes' => $c->schemes,
            'notes' => $c->notes,
        ];
    }
}
