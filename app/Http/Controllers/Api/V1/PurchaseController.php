<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelPurchase;
use App\Actions\ConfirmPurchase;
use App\Domain\Purchases\PurchasePricer;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\Store;
use App\Models\Supplier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class PurchaseController extends Controller
{
    public function __construct(private PurchasePricer $pricer) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', Rule::in([Purchase::DRAFT, Purchase::CONFIRMED, Purchase::CANCELLED, 'due'])],
            'supplier_id' => ['nullable', 'integer'],
            'search' => ['nullable', 'string', 'max:50'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);
        $query = Purchase::with('supplier:id,name')->withCount('items')->where('store_id', $request->user()->store_id);
        if (($filters['status'] ?? null) === 'due') {
            $query->where('status', Purchase::CONFIRMED)->whereColumn('paid_paise', '<', 'total_paise');
        } elseif (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes($filters['search'], '%_\\').'%';
            $query->where(fn ($q) => $q->where('purchase_no', 'like', $like)->orWhere('supplier_invoice_no', 'like', $like));
        }
        if (! empty($filters['from'])) {
            $query->where('received_date', '>=', $filters['from']);
        }
        if (! empty($filters['to'])) {
            $query->where('received_date', '<=', $filters['to']);
        }
        $page = $query->orderByDesc('received_date')->orderByDesc('id')->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Purchase $p) => $this->row($p)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /** Server-side figures for the lines on the screen, before anything is saved. */
    public function preview(Request $request): JsonResponse
    {
        $data = $this->validatedDraft($request, true);
        $supplier = isset($data['supplier_id']) ? Supplier::where('store_id', $request->user()->store_id)->find($data['supplier_id']) : null;

        return response()->json($this->pricer->price($request->user()->store, $supplier, $data['lines'], (int) ($data['other_charges_paise'] ?? 0)));
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validatedDraft($request);
        $user = $request->user();
        $supplier = Supplier::where('store_id', $user->store_id)->where('is_active', true)->findOrFail($data['supplier_id']);
        if ($duplicate = $this->duplicate($supplier, $data, null)) {
            return $duplicate;
        }

        $purchase = DB::transaction(function () use ($data, $user, $supplier) {
            $purchase = Purchase::create(['ulid' => (string) Str::ulid(), 'store_id' => $user->store_id, 'supplier_id' => $supplier->id, 'user_id' => $user->id, 'status' => Purchase::DRAFT, 'received_date' => ShopClock::todayString()]);
            $this->writeDraft($purchase, $supplier, $data, $user->store);
            Audit::log('purchases.draft_saved', $purchase);

            return $purchase;
        });

        return response()->json($this->present($purchase->refresh()), 201);
    }

    public function update(Request $request, Purchase $purchase): JsonResponse
    {
        abort_unless($purchase->store_id === $request->user()->store_id, 404);
        if (! $purchase->isDraft()) {
            throw new BusinessRuleException('purchase_not_draft', 'A confirmed purchase cannot be edited.');
        }
        $data = $this->validatedDraft($request);
        $supplier = Supplier::where('store_id', $purchase->store_id)->where('is_active', true)->findOrFail($data['supplier_id']);
        if ($duplicate = $this->duplicate($supplier, $data, $purchase)) {
            return $duplicate;
        }

        $store = $request->user()->store;
        DB::transaction(function () use ($purchase, $supplier, $data, $store) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isDraft()) {
                throw new BusinessRuleException('purchase_not_draft', 'A confirmed purchase cannot be edited.');
            }
            $purchase->items()->delete();
            $this->writeDraft($purchase, $supplier, $data, $store);
        });

        return response()->json($this->present($purchase->refresh()));
    }

    public function show(Request $request, Purchase $purchase): JsonResponse
    {
        abort_unless($purchase->store_id === $request->user()->store_id, 404);

        return response()->json($this->present($purchase));
    }

    public function confirm(Request $request, Purchase $purchase, ConfirmPurchase $action): JsonResponse
    {
        abort_unless($purchase->store_id === $request->user()->store_id, 404);
        $data = $request->validate([
            'payment' => ['nullable', 'array'],
            'payment.amount_paise' => ['required_with:payment', 'integer', 'min:0', 'max:100000000000'],
            'payment.method' => ['required_with:payment', Rule::in(Payment::METHODS)],
            'payment.reference_no' => ['nullable', 'string', 'max:50'],
        ]);
        $payment = isset($data['payment']) && (int) $data['payment']['amount_paise'] > 0 ? $data['payment'] : null;

        return response()->json($this->present($action->handle($request->user(), $purchase, $payment)));
    }

    public function cancel(Request $request, Purchase $purchase, CancelPurchase $action): JsonResponse
    {
        abort_unless($purchase->store_id === $request->user()->store_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:300']]);

        return response()->json($this->present($action->handle($request->user(), $purchase, trim($data['reason']))));
    }

    public function destroy(Request $request, Purchase $purchase): JsonResponse
    {
        abort_unless($purchase->store_id === $request->user()->store_id, 404);
        DB::transaction(function () use ($purchase) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isDraft()) {
                throw new BusinessRuleException('purchase_not_draft', 'Only a draft can be deleted.');
            }
            $purchase->items()->delete();
            $purchase->delete();
        });
        Audit::log('purchases.draft_deleted', null, old: ['purchase_id' => $purchase->id, 'supplier_id' => $purchase->supplier_id]);

        return response()->json(null, 204);
    }

    /** @return array<string, mixed> */
    private function validatedDraft(Request $request, bool $preview = false): array
    {
        $data = $request->validate([
            'supplier_id' => [$preview ? 'nullable' : 'required', 'integer'],
            'supplier_invoice_no' => ['nullable', 'string', 'max:50'],
            'invoice_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.ShopClock::todayString()],
            'received_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.ShopClock::todayString()],
            'due_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:'.($request->input('received_date') ?: ShopClock::todayString())],
            'other_charges_paise' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'invoice_total_paise' => ['nullable', 'integer', 'min:0', 'max:100000000000'],
            'notes' => ['nullable', 'string', 'max:500'],
            'confirm_duplicate' => ['boolean'],
            'lines' => [$preview ? 'nullable' : 'required', 'array', 'max:200'],
            'lines.*.product_id' => ['required', 'integer'],
            'lines.*.unit' => ['nullable', Rule::in(['pack', 'base'])],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:10000000'],
            'lines.*.free_qty' => ['nullable', 'integer', 'min:0', 'max:10000000'],
            'lines.*.rate_paise' => ['required', 'integer', 'min:0', 'max:100000000'],
            // Two decimals at most: what the decimal(5,2) columns keep, so the stored percent reproduces the stored paise.
            'lines.*.discount_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'max:100'],
            'lines.*.tax_rate_percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/', 'numeric', 'max:100'],
            'lines.*.batch_no' => ['nullable', 'string', 'max:40'],
            'lines.*.expiry_month' => ['nullable', 'date_format:Y-m'],
            'lines.*.mfg_month' => ['nullable', 'date_format:Y-m'],
            'lines.*.mrp_paise' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'lines.*.selling_price_paise' => ['nullable', 'integer', 'min:1', 'max:100000000'],
        ]);
        $data['lines'] = array_map(fn (array $line) => $line + [
            'expiry_date' => isset($line['expiry_month']) ? Carbon::createFromFormat('!Y-m', $line['expiry_month'])->endOfMonth()->toDateString() : null,
            'mfg_date' => isset($line['mfg_month']) ? $line['mfg_month'].'-01' : null,
        ], $data['lines'] ?? []);

        return $data;
    }

    /** Same supplier invoice number twice is usually the same invoice typed twice: warn once, then allow. */
    private function duplicate(Supplier $supplier, array $data, ?Purchase $except): ?JsonResponse
    {
        if (empty($data['supplier_invoice_no']) || ! empty($data['confirm_duplicate'])) {
            return null;
        }
        $same = Purchase::where('supplier_id', $supplier->id)->where('supplier_invoice_no', $data['supplier_invoice_no'])
            ->where('status', '!=', Purchase::CANCELLED)
            ->when($except, fn ($q) => $q->where('id', '!=', $except->id))
            ->first();
        if ($same === null) {
            return null;
        }

        return response()->json([
            'code' => 'duplicate_supplier_invoice',
            'message' => 'An invoice with this number from this supplier was already entered.',
            'purchase' => ['id' => $same->id, 'purchase_no' => $same->purchase_no, 'status' => $same->status, 'total_paise' => $same->total_paise, 'received_date' => $same->received_date->toDateString()],
        ], 409);
    }

    /** Prices the lines on the server and stores header and lines; the priced errors are refused as a rule. */
    private function writeDraft(Purchase $purchase, Supplier $supplier, array $data, Store $store): void
    {
        $priced = $this->pricer->price($store, $supplier, $data['lines'], (int) ($data['other_charges_paise'] ?? 0));
        if ($priced['errors'] !== []) {
            $first = $priced['errors'][0];
            throw new BusinessRuleException($first['code'], 'A line cannot be saved: '.$first['code'], ['line_errors' => $priced['errors']]);
        }
        $received = $data['received_date'] ?? ShopClock::todayString();
        $due = $data['due_date'] ?? ($supplier->payment_terms_days > 0 ? Carbon::parse($received)->addDays($supplier->payment_terms_days)->toDateString() : null);

        $purchase->update($priced['totals'] + [
            'supplier_id' => $supplier->id,
            'supplier_invoice_no' => isset($data['supplier_invoice_no']) ? trim($data['supplier_invoice_no']) ?: null : null,
            'invoice_date' => $data['invoice_date'] ?? null,
            'received_date' => $received,
            'due_date' => $due,
            'invoice_total_paise' => $data['invoice_total_paise'] ?? null,
            'inter_state' => $priced['inter_state'],
            'notes' => $data['notes'] ?? null,
        ]);
        foreach ($priced['lines'] as $number => $line) {
            $purchase->items()->create(PurchasePricer::storable($line) + ['line_no' => $number + 1]);
        }
    }

    /** @return array<string, mixed> */
    private function row(Purchase $p): array
    {
        return [
            'id' => $p->id,
            'purchase_no' => $p->purchase_no,
            'status' => $p->status,
            'supplier' => ['id' => $p->supplier_id, 'name' => $p->supplier->name],
            'supplier_invoice_no' => $p->supplier_invoice_no,
            'invoice_date' => $p->invoice_date?->toDateString(),
            'received_date' => $p->received_date->toDateString(),
            'total_paise' => $p->total_paise,
            'paid_paise' => $p->paid_paise,
            'due_date' => $p->due_date?->toDateString(),
            'lines' => array_key_exists('items_count', $p->getAttributes()) ? (int) $p->getAttributes()['items_count'] : $p->items()->count(),
        ];
    }

    /** @return array<string, mixed> */
    private function present(Purchase $p): array
    {
        $p->loadMissing(['supplier', 'items', 'user']);
        $payments = $p->payments()->orderBy('id')->get();

        return $this->row($p) + [
            'supplier' => ['id' => $p->supplier_id, 'name' => $p->supplier->name, 'gstin' => $p->supplier->gstin, 'balance_paise' => $p->supplier->balance_paise, 'payment_terms_days' => $p->supplier->payment_terms_days],
            'inter_state' => $p->inter_state,
            'other_charges_paise' => $p->other_charges_paise,
            'invoice_total_paise' => $p->invoice_total_paise,
            'notes' => $p->notes,
            'by' => $p->user->name,
            'confirmed_at' => $p->confirmed_at?->toIso8601String(),
            'cancelled' => $p->cancelled_at ? ['at' => $p->cancelled_at->toIso8601String(), 'reason' => $p->cancel_reason] : null,
            'totals' => [
                'gross_paise' => $p->gross_paise, 'discount_paise' => $p->discount_paise, 'taxable_paise' => $p->taxable_paise,
                'cgst_paise' => $p->cgst_paise, 'sgst_paise' => $p->sgst_paise, 'igst_paise' => $p->igst_paise, 'cess_paise' => $p->cess_paise,
                'other_charges_paise' => $p->other_charges_paise, 'round_off_paise' => $p->round_off_paise, 'total_paise' => $p->total_paise,
            ],
            'items' => $p->items->map(fn (PurchaseItem $i) => [
                'line_no' => $i->line_no,
                'product_id' => $i->product_id,
                'product_name' => $i->product_name,
                'hsn_code' => $i->hsn_code,
                'unit_code' => $i->unit_code,
                'unit_factor' => $i->unit_factor,
                'qty' => $i->qty,
                'free_qty' => $i->free_qty,
                'qty_base' => $i->qty_base,
                'rate_paise' => $i->rate_paise,
                'discount_percent' => (string) $i->discount_percent,
                'discount_paise' => $i->discount_paise,
                'tax_rate_percent' => (string) $i->tax_rate_percent,
                'taxable_paise' => $i->taxable_paise,
                'tax_paise' => $i->cgst_paise + $i->sgst_paise + $i->igst_paise + $i->cess_paise,
                'line_total_paise' => $i->line_total_paise,
                'batch_no' => $i->batch_no,
                'expiry_date' => $i->expiry_date?->toDateString(),
                'mfg_date' => $i->mfg_date?->toDateString(),
                'mrp_paise' => $i->mrp_paise,
                'selling_price_paise' => $i->selling_price_paise,
                'cost_rate' => (string) $i->cost_rate,
                'batch_id' => $i->batch_id,
            ]),
            'payments' => $payments->map(fn (Payment $pay) => ['direction' => $pay->direction, 'method' => $pay->method, 'amount_paise' => $pay->amount_paise, 'reference_no' => $pay->reference_no, 'at' => $pay->created_at->toIso8601String()]),
        ];
    }
}
