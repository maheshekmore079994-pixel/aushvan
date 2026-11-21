<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Stock that is already on the shelf when the shop starts using the system
 * (or stock found later that was never entered). Each line becomes a batch
 * with its own cost, MRP and expiry, and a ledger entry of type "opening".
 */
final class AddOpeningStock
{
    public function __construct(private StockLedger $ledger) {}

    /**
     * @param  list<array{qty: int, unit?: string, cost_paise: int, mrp_paise?: int|null, selling_price_paise?: int|null, batch_no?: string|null, expiry_date?: string|null, mfg_date?: string|null}>  $items
     *                                                                                                                                                                                                         qty is in the given unit ("pack" = pricing unit, "base"); cost and prices are per pricing unit.
     */
    public function handle(User $user, Product $product, array $items, ?string $note = null): StockAdjustment
    {
        return DB::transaction(function () use ($user, $product, $items, $note) {
            $product->load('units');
            $pricingFactor = $product->pricingUnit()->factor_to_base;
            $today = ShopClock::todayString();

            $adjustment = StockAdjustment::create([
                'ulid' => (string) Str::ulid(),
                'store_id' => $product->store_id,
                'reason' => 'opening',
                'adjust_date' => $today,
                'note' => $note,
                'user_id' => $user->id,
            ]);

            foreach ($items as $index => $item) {
                $batchNo = trim((string) ($item['batch_no'] ?? '')) ?: null;
                $expiry = $item['expiry_date'] ?? null;

                if ($product->tracks_batch && $batchNo === null) {
                    throw new BusinessRuleException('batch_required', 'Batch number is required for this product.', ['line' => $index]);
                }
                if ($product->tracks_expiry && $expiry === null) {
                    throw new BusinessRuleException('expiry_required', 'Expiry date is required for this product.', ['line' => $index]);
                }
                if ($expiry !== null && $expiry < $today) {
                    throw new BusinessRuleException('already_expired', 'This stock has already expired and cannot be added for sale.', ['line' => $index]);
                }

                $qtyBase = (int) $item['qty'] * (($item['unit'] ?? 'pack') === 'base' ? 1 : $pricingFactor);
                $mrp = (int) ($item['mrp_paise'] ?? $product->mrp_paise);
                $selling = (int) ($item['selling_price_paise'] ?? ($mrp > 0 ? min($mrp, $product->selling_price_paise ?: $mrp) : $product->selling_price_paise));

                if ($mrp > 0 && $selling > $mrp) {
                    throw new BusinessRuleException('price_above_mrp', 'Selling price cannot be more than MRP.', ['line' => $index]);
                }

                $batch = StockBatch::create([
                    'store_id' => $product->store_id,
                    'product_id' => $product->id,
                    'batch_no' => $batchNo,
                    'mfg_date' => $item['mfg_date'] ?? null,
                    'expiry_date' => $expiry,
                    'mrp_paise' => $mrp,
                    'selling_price_paise' => $selling,
                    'cost_rate' => Rate::perBase((int) $item['cost_paise'], $pricingFactor),
                    'received_date' => $today,
                ])->refresh();

                $this->ledger->record($batch, StockLedger::SELLABLE, $qtyBase, 'opening', $adjustment, $user->id, $note);
            }

            Audit::log('stock.opening_added', $product, new: ['adjustment_id' => $adjustment->id, 'lines' => count($items)]);

            return $adjustment;
        });
    }
}
