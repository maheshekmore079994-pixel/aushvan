<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\AddOpeningStock;
use App\Actions\AdjustStock;
use App\Domain\Catalog\ProductPresenter;
use App\Domain\Catalog\ProductQuery;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class StockController extends Controller
{
    /** Counts for the dashboard and the alert strip. */
    public function summary(Request $request, StockLedger $ledger): JsonResponse
    {
        $storeId = $request->user()->store_id;
        $today = ShopClock::todayString();
        $active = fn () => ProductQuery::forStore($storeId)->where('is_active', true);
        $batches = fn () => StockBatch::where('store_id', $storeId);

        $summary = [
            'products' => $active()->count(),
            'medical_products' => $active()->where('type', Product::TYPE_MEDICAL)->count(),
            'general_products' => $active()->where('type', Product::TYPE_GENERAL)->count(),
            'low_stock' => ProductQuery::whereStockLevel($active(), 'low')->count(),
            'out_of_stock' => ProductQuery::whereStockLevel($active(), 'out')->count(),
            'expired_batches' => $batches()->where('qty_sellable', '>', 0)->whereDate('expiry_date', '<', $today)->count(),
            'schedule_not_set' => $active()->where('type', Product::TYPE_MEDICAL)
                ->whereHas('medicine', fn ($q) => $q->whereNull('drug_schedule_id'))->count(),
        ];

        foreach ([30, 60, 90] as $days) {
            $summary["expiring_{$days}"] = $batches()->where('qty_sellable', '>', 0)
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', ShopClock::today()->addDays($days)->toDateString())
                ->count();
        }

        if ($request->user()->can(Permission::STOCK_VIEW_COST)) {
            // Rates are multiplied batch by batch in PHP; they are never summed in SQL.
            $value = 0;
            $batches()->where(fn ($q) => $q->where('qty_sellable', '>', 0)->orWhere('qty_unsellable', '>', 0))
                ->select(['id', 'cost_rate', 'qty_sellable', 'qty_unsellable'])
                ->chunkById(1000, function ($chunk) use (&$value) {
                    foreach ($chunk as $batch) {
                        $value += Rate::amount($batch->cost_rate, $batch->qty_sellable + $batch->qty_unsellable);
                    }
                });
            $summary['stock_value_paise'] = $value;
        }

        if ($request->user()->isOwner()) {
            $summary['ledger_mismatches'] = count($ledger->findMismatches($storeId));
        }

        return response()->json($summary);
    }

    /** Batch-level list behind Expiry Alerts: expired, expiring within N days, or held as not for sale. */
    public function batches(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['required', Rule::in(['expired', 'expiring', 'unsellable'])],
            'days' => ['nullable', 'integer', 'min:1', 'max:730'],
        ]);
        $today = ShopClock::todayString();
        $showCost = $request->user()->can(Permission::STOCK_VIEW_COST);

        $query = StockBatch::where('store_id', $request->user()->store_id)->with('product.units.unit');

        match ($filters['status']) {
            'expired' => $query->where('qty_sellable', '>', 0)->whereDate('expiry_date', '<', $today),
            'expiring' => $query->where('qty_sellable', '>', 0)
                ->whereDate('expiry_date', '>=', $today)
                ->whereDate('expiry_date', '<=', ShopClock::today()->addDays((int) ($filters['days'] ?? 90))->toDateString()),
            'unsellable' => $query->where('qty_unsellable', '>', 0),
        };

        $unsellable = $filters['status'] === 'unsellable';
        $rows = $query->orderBy('expiry_date')->limit(500)->get()->map(function (StockBatch $batch) use ($showCost, $unsellable) {
            $pricing = $batch->product->pricingUnit();
            // Only the stock this list is about: what is set aside, or what is still marked for sale.
            $value = $showCost ? ['value_paise' => Rate::amount($batch->cost_rate, $unsellable ? $batch->qty_unsellable : $batch->qty_sellable)] : [];

            return $value + ProductPresenter::batch($batch, $pricing->factor_to_base, $showCost) + [
                'product_id' => $batch->product_id,
                'product_name' => $batch->product->name,
                'product_type' => $batch->product->type,
                'base_unit' => $batch->product->baseUnit()->unit->code,
                'pricing_unit' => $pricing->unit->code,
                'pack_size' => $pricing->factor_to_base,
            ];
        });

        return response()->json(['data' => $rows]);
    }

    public function movements(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->store_id === $request->user()->store_id, 404);

        $page = InventoryMovement::where('product_id', $product->id)
            ->with(['batch:id,batch_no,expiry_date', 'user:id,name'])
            ->orderByDesc('id')
            ->paginate(50);

        return response()->json([
            'data' => collect($page->items())->map(fn (InventoryMovement $m) => [
                'id' => $m->id,
                'at' => $m->created_at->toIso8601String(),
                'type' => $m->type,
                'bucket' => $m->bucket,
                'qty_delta' => $m->qty_delta,
                'balance_after' => $m->balance_after,
                'batch_no' => $m->batch->batch_no,
                'user' => $m->user->name,
                'note' => $m->note,
            ]),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function openingStock(Request $request, Product $product, AddOpeningStock $action): JsonResponse
    {
        abort_unless($product->store_id === $request->user()->store_id, 404);

        $data = $request->validate([
            'note' => ['nullable', 'string', 'max:500'],
            'items' => ['required', 'array', 'min:1', 'max:50'],
            'items.*.qty' => ['required', 'integer', 'min:1', 'max:10000000'],
            'items.*.unit' => ['nullable', Rule::in(['pack', 'base'])],
            'items.*.cost_paise' => ['required', 'integer', 'min:0', 'max:100000000'],
            'items.*.mrp_paise' => ['nullable', 'integer', 'min:0', 'max:100000000'],
            'items.*.selling_price_paise' => ['nullable', 'integer', 'min:1', 'max:100000000'],
            'items.*.batch_no' => ['nullable', 'string', 'max:40'],
            // Medicine packs print month and year; stock is good until the last day of that month.
            'items.*.expiry_month' => ['nullable', 'date_format:Y-m'],
            'items.*.mfg_month' => ['nullable', 'date_format:Y-m'],
        ]);

        $items = array_map(fn (array $item) => $item + [
            'expiry_date' => isset($item['expiry_month']) ? Carbon::createFromFormat('!Y-m', $item['expiry_month'])->endOfMonth()->toDateString() : null,
            'mfg_date' => isset($item['mfg_month']) ? $item['mfg_month'].'-01' : null,
        ], $data['items']);

        $adjustment = $action->handle($request->user(), $product, $items, $data['note'] ?? null);

        return response()->json(['adjustment_id' => $adjustment->id], 201);
    }

    public function adjust(Request $request, StockBatch $batch, AdjustStock $action): JsonResponse
    {
        abort_unless($batch->store_id === $request->user()->store_id, 404);

        $data = $request->validate([
            'action' => ['required', Rule::in(AdjustStock::ACTIONS)],
            'qty' => ['required', 'integer', 'min:0', 'max:10000000'],
            'bucket' => ['nullable', Rule::in([StockLedger::SELLABLE, StockLedger::UNSELLABLE])],
            'note' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        if ($data['action'] !== AdjustStock::COUNT_CORRECTION && $data['qty'] < 1) {
            return response()->json(['message' => 'Quantity must be at least 1.', 'errors' => ['qty' => ['Quantity must be at least 1.']]], 422);
        }

        $action->handle($request->user(), $batch, $data['action'], $data['qty'], $data['bucket'] ?? StockLedger::SELLABLE, $data['note']);
        $batch->refresh();

        return response()->json(['qty_sellable' => $batch->qty_sellable, 'qty_unsellable' => $batch->qty_unsellable], 201);
    }
}
