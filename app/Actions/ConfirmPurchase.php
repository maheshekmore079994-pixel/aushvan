<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\SupplierLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\NumberSequencer;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Turns a draft purchase into stock. In one transaction: gives the purchase its number, makes one stock
 * batch per line (with the line's cost, MRP and expiry) and a stock movement for it, records the cost in the
 * price history, posts the invoice total to the supplier's ledger, and records any payment made now.
 */
final class ConfirmPurchase
{
    public function __construct(
        private StockLedger $stock,
        private SupplierLedger $suppliers,
        private NumberSequencer $numbers,
    ) {}

    /**
     * @param  array{amount_paise: int, method: string, reference_no?: ?string}|null  $payment  money handed over now
     */
    public function handle(User $user, Purchase $purchase, ?array $payment = null): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $payment) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if (! $purchase->isDraft()) {
                throw new BusinessRuleException('purchase_not_draft', 'This purchase has already been confirmed.');
            }
            $items = $purchase->items()->get();
            if ($items->isEmpty()) {
                throw new BusinessRuleException('purchase_empty', 'Add at least one line before confirming.');
            }
            $today = ShopClock::todayString();
            $paid = (int) ($payment['amount_paise'] ?? 0);
            if ($paid < 0 || $paid > $purchase->total_paise) {
                throw new BusinessRuleException('overpaid', 'The payment is more than the purchase total.', ['total_paise' => $purchase->total_paise]);
            }

            $store = $user->store;
            $purchaseNo = $this->numbers->next($store->id, 'purchase', 'P');

            foreach ($items as $item) {
                /** @var PurchaseItem $item */
                if ($item->expiry_date !== null && $item->expiry_date->toDateString() < $today) {
                    throw new BusinessRuleException('already_expired', 'A line has expired since the draft was saved.', ['line' => $item->line_no]);
                }
                $product = Product::with('units')->whereKey($item->product_id)->lockForUpdate()->first();
                if ($product === null || ! $product->is_active) {
                    throw new BusinessRuleException('product_missing', 'A product on this purchase is no longer available.', ['line' => $item->line_no]);
                }
                $selling = $item->selling_price_paise ?? min($product->selling_price_paise ?: $item->mrp_paise, $item->mrp_paise ?: PHP_INT_MAX);

                $batch = StockBatch::create([
                    'store_id' => $purchase->store_id,
                    'product_id' => $item->product_id,
                    'batch_no' => $item->batch_no,
                    'mfg_date' => $item->mfg_date?->toDateString(),
                    'expiry_date' => $item->expiry_date?->toDateString(),
                    'mrp_paise' => $item->mrp_paise,
                    'selling_price_paise' => $selling,
                    'cost_rate' => $item->cost_rate,
                    'received_date' => $purchase->received_date->toDateString(),
                    'supplier_id' => $purchase->supplier_id,
                    'purchase_item_id' => $item->id,
                ])->refresh();

                $this->stock->record($batch, StockLedger::SELLABLE, $item->qty_base, 'purchase', $purchase, $user->id);
                $item->update(['batch_id' => $batch->id]); // still a draft, so the line guard allows it

                $this->recordPrices($product, $item, $batch, $selling, $user->id);
            }

            $purchase->update([
                'purchase_no' => $purchaseNo,
                'paid_paise' => $paid,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'status' => Purchase::CONFIRMED,
            ]);

            $supplier = $purchase->supplier()->lockForUpdate()->firstOrFail();
            if ($purchase->total_paise > 0) { // free samples alone owe the supplier nothing
                $this->suppliers->post($supplier, 'purchase', $purchase->total_paise, 0, $purchase, $user->id, $purchaseNo.($purchase->supplier_invoice_no ? ' / '.$purchase->supplier_invoice_no : ''));
            }

            if ($paid > 0) {
                $row = Payment::create([
                    'ulid' => (string) Str::ulid(),
                    'store_id' => $purchase->store_id,
                    'direction' => 'out',
                    'method' => $payment['method'],
                    'amount_paise' => $paid,
                    'party_type' => $supplier->getMorphClass(),
                    'party_id' => $supplier->id,
                    'payable_type' => $purchase->getMorphClass(),
                    'payable_id' => $purchase->id,
                    'reference_no' => $payment['reference_no'] ?? null,
                    'business_date' => $today,
                    'user_id' => $user->id,
                    'created_at' => now(),
                ]);
                $this->suppliers->post($supplier, 'payment', 0, $paid, $row, $user->id, $purchaseNo);
            }

            Audit::log('purchases.confirmed', $purchase, new: ['purchase_no' => $purchaseNo, 'total_paise' => $purchase->total_paise, 'paid_paise' => $paid, 'lines' => $items->count()]);

            return $purchase->refresh();
        });
    }

    /**
     * The cost goes into the price history for every line; a new MRP on the pack becomes the product's MRP
     * (and caps its selling price), because the next bill must show what is printed on the pack (D-061).
     */
    private function recordPrices(Product $product, PurchaseItem $item, StockBatch $batch, int $selling, int $userId): void
    {
        $rows = [[
            'product_id' => $product->id, 'batch_id' => $batch->id, 'field' => 'cost', 'old_paise' => null,
            // Cost of one pricing unit (a pack), whatever unit the supplier billed in: integer paise through Rate.
            'new_paise' => Rate::amount((string) $item->cost_rate, $product->pricingUnit()->factor_to_base), 'source' => 'purchase', 'user_id' => $userId, 'created_at' => now(),
        ]];
        $changes = [];
        if ($item->mrp_paise > 0 && $item->mrp_paise !== $product->mrp_paise) {
            $rows[] = ['product_id' => $product->id, 'batch_id' => $batch->id, 'field' => 'mrp', 'old_paise' => $product->mrp_paise,
                'new_paise' => $item->mrp_paise, 'source' => 'purchase', 'user_id' => $userId, 'created_at' => now()];
            $changes['mrp_paise'] = $item->mrp_paise;
        }
        $newSelling = $item->selling_price_paise ?? ($item->mrp_paise > 0 ? min($product->selling_price_paise ?: $selling, $item->mrp_paise) : $product->selling_price_paise);
        if ($newSelling !== $product->selling_price_paise && $newSelling > 0) {
            $rows[] = ['product_id' => $product->id, 'batch_id' => $batch->id, 'field' => 'selling_price', 'old_paise' => $product->selling_price_paise,
                'new_paise' => $newSelling, 'source' => 'purchase', 'user_id' => $userId, 'created_at' => now()];
            $changes['selling_price_paise'] = $newSelling;
        }
        DB::table('product_price_history')->insert($rows);
        if ($changes !== []) {
            Product::whereKey($product->id)->update($changes + ['version' => $product->version + 1]);
        }
    }
}
