<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CompleteSale;
use App\Domain\Catalog\ProductQuery;
use App\Domain\Sales\SalePricer;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class SaleController extends Controller
{
    private const LINE_RULES = [
        'lines' => ['required', 'array', 'min:1', 'max:200'],
        'lines.*.product_id' => ['required', 'integer'],
        'lines.*.unit' => ['nullable', 'in:pack,base'],
        'lines.*.qty' => ['required', 'integer', 'min:1', 'max:10000000'],
        'lines.*.batch_id' => ['nullable', 'integer'],
        'lines.*.discount_percent' => ['nullable', 'numeric', 'min:0', 'max:100'],
    ];

    /** Products for the sale screen: what can be sold now, from which batches, at what price. */
    public function search(Request $request): JsonResponse
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'quick' => ['nullable', 'boolean']]);
        $storeId = $request->user()->store_id;
        $text = trim((string) ($data['q'] ?? ''));

        $query = ProductQuery::withStock(ProductQuery::forStore($storeId))
            ->where('is_active', true)
            ->with(['units.unit', 'medicine.schedule', 'batches' => fn ($q) => $q->where('qty_sellable', '>', 0)]);

        $barcode = $text !== '' ? ProductBarcode::where('store_id', $storeId)->where('barcode', $text)->with('productUnit')->first() : null;

        if ($barcode) {
            $query->where('products.id', $barcode->product_id);
        } elseif (! empty($data['quick'])) {
            $query->where('is_quick_item', true);
        } elseif ($text !== '') {
            ProductQuery::search($query, $text);
        } else {
            return response()->json(['data' => [], 'matched_barcode' => false]);
        }

        if ($barcode || ! empty($data['quick'])) {
            $products = $query->orderBy('name')->limit(24)->get();
        } else {
            // Enter on the sale screen adds the first row, so the order matters: a name that starts with
            // what was typed, then a name containing it, then matches on salt / brand / local name;
            // within each, things that can be sold now come before things that are out of stock.
            $needle = mb_strtolower($text);
            $products = $query->orderBy('name')->limit(60)->get()
                ->sortBy(function (Product $p) use ($needle) {
                    $name = mb_strtolower($p->name);
                    $rank = str_starts_with($name, $needle) ? 0 : (str_contains($name, $needle) ? 1 : 2);

                    return [(int) $p->stock_qty > 0 ? 0 : 1, $rank, $name];
                })
                ->take(12)->values();
        }

        return response()->json([
            'data' => $products->map(fn (Product $p) => $this->posProduct($p)),
            'matched_barcode' => (bool) $barcode,
            // A barcode printed on the loose unit adds a loose unit; otherwise a pack.
            'barcode_unit' => $barcode && $barcode->productUnit->is_base && ! $barcode->productUnit->is_pricing ? 'base' : 'pack',
        ]);
    }

    /** Prices the cart without saving anything. The sale screen shows exactly this. */
    public function preview(Request $request, SalePricer $pricer): JsonResponse
    {
        $data = $request->validate(self::LINE_RULES);
        $this->refuseDiscount($request, $data['lines']);
        $priced = $pricer->price($request->user()->store, $data['lines']);

        unset($priced['batches'], $priced['totals']['cost_paise']);
        $priced['lines'] = array_map(fn (array $line) => array_diff_key($line, ['cost_paise' => 1, 'cost_rate' => 1]), $priced['lines']);

        return response()->json($priced);
    }

    /**
     * A discount typed on a bill line is allowed only with the discount permission.
     * (Discount limits per role come with the discount controls; until then it is all or nothing.)
     *
     * @param  list<array<string, mixed>>  $lines
     */
    private function refuseDiscount(Request $request, array $lines): void
    {
        foreach ($lines as $line) {
            if ((float) ($line['discount_percent'] ?? 0) > 0 && ! $request->user()->can(Permission::SALES_DISCOUNT_OVERRIDE)) {
                throw new BusinessRuleException('discount_not_allowed', 'You are not allowed to give a discount.');
            }
        }
    }

    public function store(Request $request, CompleteSale $completeSale): JsonResponse
    {
        $data = $request->validate(self::LINE_RULES + [
            'ulid' => ['required', 'string', 'size:26', 'regex:/^[0-9A-HJKMNP-TV-Z]{26}$/'],
            'customer_id' => ['nullable', 'integer'],
            'notes' => ['nullable', 'string', 'max:300'],
            'override_credit_limit' => ['boolean'],
            'payments' => ['array', 'max:5'],
            'payments.*.method' => ['required', Rule::in(Payment::METHODS)],
            'payments.*.amount_paise' => ['required', 'integer', 'min:1', 'max:1000000000'],
            'payments.*.reference_no' => ['nullable', 'string', 'max:50'],
            'prescription' => ['nullable', 'array'],
            'prescription.doctor_id' => ['nullable', 'integer'],
            'prescription.prescriber_name' => ['nullable', 'string', 'max:100'],
            'prescription.prescriber_address' => ['nullable', 'string', 'max:300'],
            'prescription.prescriber_reg_no' => ['nullable', 'string', 'max:50'],
            'prescription.patient_name' => ['nullable', 'string', 'max:100'],
            'prescription.patient_address' => ['nullable', 'string', 'max:300'],
            'prescription.prescription_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:'.ShopClock::todayString()],
            'pharmacist' => ['nullable', 'array'],
            'pharmacist.user_id' => ['nullable', 'integer'],
            'pharmacist.pin' => ['nullable', 'string', 'max:6'],
        ]);
        $this->refuseDiscount($request, $data['lines']);

        $sale = $completeSale->handle($request->user(), $data);

        return response()->json($this->bill($sale), $completeSale->wasReplay ? 200 : 201)
            ->header('Idempotency-Replayed', $completeSale->wasReplay ? 'true' : 'false');
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d'],
            'customer_id' => ['nullable', 'integer'],
        ]);
        $search = trim((string) ($filters['search'] ?? ''));

        $page = Sale::where('store_id', $request->user()->store_id)
            ->with('payments')
            ->when($filters['from'] ?? null, fn ($q, $date) => $q->whereDate('sale_date', '>=', $date))
            ->when($filters['to'] ?? null, fn ($q, $date) => $q->whereDate('sale_date', '<=', $date))
            ->when($filters['customer_id'] ?? null, fn ($q, $id) => $q->where('customer_id', $id))
            ->when($search !== '', fn ($q) => $q->where(fn ($w) => $w
                ->where('invoice_no', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhere('buyer_name', 'like', '%'.addcslashes($search, '%_\\').'%')
                ->orWhereIn('id', SaleItem::select('sale_id')->where('product_name', 'like', '%'.addcslashes($search, '%_\\').'%'))))
            ->orderByDesc('id')
            ->paginate(25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Sale $sale) => [
                'id' => $sale->id,
                'invoice_no' => $sale->invoice_no,
                'completed_at' => $sale->completed_at->toIso8601String(),
                'buyer_name' => $sale->buyer_name,
                'total_paise' => $sale->total_paise,
                'paid_paise' => $sale->paid_paise,
                'credit_paise' => $sale->credit_paise,
                'methods' => $sale->payments->pluck('method')->unique()->values(),
                'status' => $sale->status,
            ]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(Request $request, Sale $sale): JsonResponse
    {
        abort_unless($sale->store_id === $request->user()->store_id, 404);

        return response()->json($this->bill($sale));
    }

    /** Pharmacists who can approve a bill with their PIN (names only). */
    public function pharmacists(Request $request): JsonResponse
    {
        return response()->json(['data' => User::where('store_id', $request->user()->store_id)
            ->where('is_active', true)->where('is_pharmacist', true)->whereNotNull('pin')
            ->orderBy('name')->get(['id', 'name'])]);
    }

    /** @return array<string, mixed> */
    private function posProduct(Product $product): array
    {
        $pack = $product->pricingUnit();
        $schedule = $product->medicine?->schedule;
        $batches = $product->batches
            ->reject(fn (StockBatch $b) => $b->isExpired())
            ->sortBy(fn (StockBatch $b) => [$b->expiry_date?->toDateString() ?? '9999-12-31', $b->id])
            ->values();

        return [
            'id' => $product->id,
            'type' => $product->type,
            'name' => $product->name,
            'name_local' => $product->name_local,
            'generic_name' => $product->medicine?->generic_name,
            'strength' => $product->medicine?->strength,
            'schedule' => $schedule?->code,
            'needs_prescription' => (bool) $schedule?->requires_prescription,
            'base_unit' => $product->baseUnit()->unit->code,
            'pricing_unit' => $pack->unit->code,
            'pack_size' => $pack->factor_to_base,
            'allow_loose_sale' => $product->allow_loose_sale && $pack->factor_to_base > 1,
            'tracks_batch' => $product->tracks_batch,
            'rack_location' => $product->rack_location,
            'price_paise' => $batches->first()?->selling_price_paise ?? $product->selling_price_paise,
            'stock_qty' => (int) $product->stock_qty,
            'expired_qty' => (int) $product->expired_qty,
            'batches' => $batches->map(fn (StockBatch $b) => [
                'id' => $b->id,
                'batch_no' => $b->batch_no,
                'expiry_date' => $b->expiry_date?->toDateString(),
                'days_to_expiry' => $b->daysToExpiry(),
                'qty' => $b->qty_sellable,
                'price_paise' => $b->selling_price_paise,
                'mrp_paise' => $b->mrp_paise,
            ]),
        ];
    }

    /** Everything needed to show and print the bill. @return array<string, mixed> */
    private function bill(Sale $sale): array
    {
        $sale->load(['items', 'payments', 'customer', 'prescription', 'user', 'pharmacist', 'returns', 'cancelledBy']);
        $store = $sale->user->store()->with('settings')->first();

        $taxSummary = $sale->items
            ->filter(fn (SaleItem $i) => $i->tax_kind === 'taxable')
            ->groupBy(fn (SaleItem $i) => (string) $i->tax_rate_percent)
            ->map(fn ($items, $rate) => [
                'rate_percent' => $rate,
                'taxable_paise' => $items->sum('taxable_paise'),
                'cgst_paise' => $items->sum('cgst_paise'),
                'sgst_paise' => $items->sum('sgst_paise'),
                'igst_paise' => $items->sum('igst_paise'),
                'cess_paise' => $items->sum('cess_paise'),
            ])->sortKeys()->values();

        return [
            'id' => $sale->id,
            'invoice_no' => $sale->invoice_no,
            'doc_kind' => $sale->doc_kind,
            'status' => $sale->status,
            'cancelled' => $sale->status === 'cancelled' ? ['by' => $sale->cancelledBy?->name, 'at' => $sale->cancelled_at?->toIso8601String(), 'reason' => $sale->cancel_reason] : null,
            // What the screen may offer: cancelling is for the same business day only, and never after a return.
            'can_cancel' => $sale->status === 'completed' && $sale->sale_date->toDateString() === ShopClock::todayString() && $sale->returns->isEmpty(),
            'can_return' => $sale->status === 'completed' && $sale->items->contains(fn (SaleItem $i) => $i->returned_qty_base < $i->qty_base),
            'returns' => $sale->returns->map(fn ($r) => ['id' => $r->id, 'return_no' => $r->return_no, 'return_date' => $r->return_date->toDateString(), 'total_paise' => $r->total_paise, 'refund_mode' => $r->refund_mode])->values(),
            'sale_date' => $sale->sale_date->toDateString(),
            'completed_at' => $sale->completed_at->toIso8601String(),
            'cashier' => $sale->user->name,
            'pharmacist' => $sale->pharmacist ? ['name' => $sale->pharmacist->name, 'reg_no' => $sale->pharmacist->pharmacist_reg_no] : null,
            'customer' => $sale->customer ? [
                'id' => $sale->customer->id, 'name' => $sale->customer->name, 'mobile' => $sale->customer->mobile,
                'village' => $sale->customer->village, 'balance_paise' => $sale->customer->balance_paise,
            ] : null,
            'prescription' => $sale->prescription?->only(['serial_no', 'patient_name', 'patient_address', 'prescriber_name', 'prescriber_address', 'prescriber_reg_no']),
            'shop' => [
                'name' => $store->name, 'address' => $store->address, 'village' => $store->village, 'taluka' => $store->taluka,
                'district' => $store->district, 'pincode' => $store->pincode, 'phone' => $store->phone,
                'gst_mode' => $store->gst_mode->value, 'gstin' => $store->gstin,
                'drug_licences' => array_values(array_filter([$store->setting('drug_licence_20'), $store->setting('drug_licence_21'), $store->setting('drug_licence_20f')])),
                'footer' => $store->setting('bill_footer'),
            ],
            // Drug licence numbers are printed on any bill that contains a medicine.
            'has_medicines' => Product::withTrashed()->whereIn('id', $sale->items->pluck('product_id'))
                ->where('type', Product::TYPE_MEDICAL)->exists(),
            'items' => $sale->items->map(fn (SaleItem $i) => [
                'line_no' => $i->line_no, 'product_id' => $i->product_id, 'product_name' => $i->product_name,
                'hsn_code' => $i->hsn_code, 'schedule_code' => $i->schedule_code, 'batch_no' => $i->batch_no,
                'expiry_date' => $i->expiry_date?->toDateString(), 'unit_code' => $i->unit_code, 'qty' => $i->qty,
                'mrp_paise' => $i->mrp_paise, 'pack_price_paise' => $i->pack_price_paise, 'pack_factor' => $i->pack_factor,
                'unit_factor' => $i->unit_factor, 'gross_paise' => $i->gross_paise, 'discount_paise' => $i->discount_paise,
                'tax_rate_percent' => (string) $i->tax_rate_percent, 'line_total_paise' => $i->line_total_paise,
                'returned_qty' => intdiv($i->returned_qty_base, $i->unit_factor),
            ]),
            'totals' => $sale->only([
                'gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise',
                'round_off_paise', 'total_paise', 'paid_paise', 'credit_paise',
            ]),
            'tax_summary' => $taxSummary,
            'payments' => $sale->payments->where('direction', 'in')->map->only(['method', 'amount_paise', 'reference_no'])->values(),
            'refunds' => $sale->payments->where('direction', 'out')->map->only(['method', 'amount_paise', 'reference_no'])->values(),
        ];
    }
}
