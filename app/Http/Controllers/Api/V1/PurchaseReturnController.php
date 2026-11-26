<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\ReturnToSupplier;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\ShopClock;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\PurchaseReturn;
use App\Models\PurchaseReturnItem;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseReturnController extends Controller
{
    /** Batches that still hold stock, to pick from for a return: by product name or batch number, optionally one supplier's. */
    public function lots(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'supplier_id' => ['nullable', 'integer'],
            'expired' => ['nullable', 'boolean'],
        ]);
        $query = StockBatch::with(['product:id,name,type', 'product.units.unit'])
            ->where('store_id', $request->user()->store_id)
            ->where(fn ($q) => $q->where('qty_sellable', '>', 0)->orWhere('qty_unsellable', '>', 0));
        if (! empty($filters['supplier_id'])) {
            $query->where('supplier_id', (int) $filters['supplier_id']);
        }
        if (! empty($filters['expired'])) {
            $query->whereNotNull('expiry_date')->where('expiry_date', '<', ShopClock::todayString());
        }
        if (! empty($filters['search'])) {
            $like = '%'.addcslashes(mb_strtolower($filters['search']), '%_\\').'%';
            $query->where(fn ($q) => $q->whereHas('product', fn ($p) => $p->where('search_text', 'like', $like))->orWhereRaw('LOWER(batch_no) LIKE ?', [$like]));
        }
        $batches = $query->orderBy('expiry_date')->limit(50)->get();

        return response()->json(['data' => $batches->map(fn (StockBatch $b) => [
            'id' => $b->id,
            'product_id' => $b->product_id,
            'product_name' => $b->product->name,
            'batch_no' => $b->batch_no,
            'expiry_date' => $b->expiry_date?->toDateString(),
            'expired' => $b->isExpired(),
            'qty_sellable' => $b->qty_sellable,
            'qty_unsellable' => $b->qty_unsellable,
            'cost_rate' => (string) $b->cost_rate,
            'supplier_id' => $b->supplier_id,
            'base_unit' => $b->product->baseUnit()->unit->code,
            'pack_size' => $b->product->pricingUnit()->factor_to_base,
            'pricing_unit' => $b->product->pricingUnit()->unit->code,
        ])]);
    }

    public function store(Request $request, ReturnToSupplier $action): JsonResponse
    {
        $data = $request->validate([
            'supplier_id' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:3', 'max:300'],
            'settlement' => ['required', Rule::in(PurchaseReturn::SETTLEMENTS)],
            'refund_method' => ['required_if:settlement,refund_received', 'nullable', Rule::in(Payment::METHODS)],
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.batch_id' => ['required', 'integer'],
            'lines.*.bucket' => ['required', Rule::in([StockLedger::SELLABLE, StockLedger::UNSELLABLE])],
            'lines.*.qty_base' => ['required', 'integer', 'min:1', 'max:10000000'],
        ]);
        $data['reason'] = trim($data['reason']);

        return response()->json($this->present($action->handle($request->user(), $data)), 201);
    }

    public function show(Request $request, PurchaseReturn $purchaseReturn): JsonResponse
    {
        abort_unless($purchaseReturn->store_id === $request->user()->store_id, 404);

        return response()->json($this->present($purchaseReturn));
    }

    /** @return array<string, mixed> */
    private function present(PurchaseReturn $r): array
    {
        $r->loadMissing(['supplier', 'items', 'user']);

        return [
            'id' => $r->id,
            'return_no' => $r->return_no,
            'return_date' => $r->return_date->toDateString(),
            'created_at' => $r->created_at->toIso8601String(),
            'supplier' => ['id' => $r->supplier_id, 'name' => $r->supplier->name, 'balance_paise' => $r->supplier->balance_paise],
            'reason' => $r->reason,
            'settlement' => $r->settlement,
            'refund_method' => $r->refund_method,
            'by' => $r->user->name,
            'items' => $r->items->map(fn (PurchaseReturnItem $i) => [
                'line_no' => $i->line_no,
                'product_name' => $i->product_name,
                'batch_no' => $i->batch_no,
                'bucket' => $i->bucket,
                'qty_base' => $i->qty_base,
                'cost_rate' => (string) $i->cost_rate,
                'tax_rate_percent' => (string) $i->tax_rate_percent,
                'taxable_paise' => $i->taxable_paise,
                'line_total_paise' => $i->line_total_paise,
            ]),
            'totals' => [
                'taxable_paise' => $r->taxable_paise, 'cgst_paise' => $r->cgst_paise, 'sgst_paise' => $r->sgst_paise,
                'igst_paise' => $r->igst_paise, 'cess_paise' => $r->cess_paise, 'round_off_paise' => $r->round_off_paise, 'total_paise' => $r->total_paise,
            ],
        ];
    }
}
