<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\SupplierLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\ShopClock;
use App\Exceptions\BusinessRuleException;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\StockBatch;
use App\Models\SupplierLedgerEntry;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Undoes a confirmed purchase that was entered wrongly, allowed only while none of its stock has moved:
 * every batch it made still holds exactly what came in. Stock goes out with linked movements, the supplier
 * ledger is reversed, money paid comes back as a mirrored payment in, and a product price the purchase
 * changed (MRP / selling price) is put back when nothing changed it since. Otherwise a purchase return is the way.
 */
final class CancelPurchase
{
    public function __construct(private StockLedger $stock, private SupplierLedger $suppliers) {}

    public function handle(User $user, Purchase $purchase, string $reason): Purchase
    {
        return DB::transaction(function () use ($user, $purchase, $reason) {
            $purchase = Purchase::whereKey($purchase->id)->lockForUpdate()->firstOrFail();
            if ($purchase->status !== Purchase::CONFIRMED) {
                throw new BusinessRuleException('purchase_not_confirmed', 'Only a confirmed purchase can be cancelled.');
            }
            // Party first, then batches: the same order as the other actions, so two of them cannot deadlock.
            $supplier = $purchase->supplier()->lockForUpdate()->firstOrFail();

            foreach ($purchase->items as $item) {
                $batch = StockBatch::whereKey($item->batch_id)->lockForUpdate()->firstOrFail();
                $moved = InventoryMovement::where('batch_id', $batch->id)->count() > 1
                    || $batch->qty_sellable !== $item->qty_base || $batch->qty_unsellable !== 0;
                if ($moved) {
                    throw new BusinessRuleException('purchase_stock_moved', 'Some of this stock has already been sold or moved; make a purchase return instead.', ['line' => $item->line_no]);
                }
                $this->stock->record($batch, StockLedger::SELLABLE, -$item->qty_base, 'purchase_cancel', $purchase, $user->id, $reason);
                $this->restorePrices($item->product_id, $batch->id, $user->id);
            }

            $original = SupplierLedgerEntry::where('supplier_id', $supplier->id)
                ->where('source_type', $purchase->getMorphClass())->where('source_id', $purchase->id)->where('type', 'purchase')->first();
            $this->suppliers->post($supplier, 'reversal', 0, $purchase->total_paise, $purchase, $user->id, $reason, $original?->id);

            foreach ($purchase->payments()->where('direction', 'out')->get() as $paid) {
                /** @var Payment $paid */
                $back = Payment::create([
                    'ulid' => (string) Str::ulid(),
                    'store_id' => $purchase->store_id,
                    'direction' => 'in',
                    'method' => $paid->method,
                    'amount_paise' => $paid->amount_paise,
                    'party_type' => $supplier->getMorphClass(),
                    'party_id' => $supplier->id,
                    'payable_type' => $purchase->getMorphClass(),
                    'payable_id' => $purchase->id,
                    'reference_no' => $paid->reference_no,
                    'business_date' => ShopClock::todayString(),
                    'user_id' => $user->id,
                    'note' => $reason,
                    'created_at' => now(),
                ]);
                $this->suppliers->post($supplier, 'reversal', $paid->amount_paise, 0, $back, $user->id, $reason);
            }

            $purchase->update([
                'status' => Purchase::CANCELLED,
                'cancelled_by' => $user->id,
                'cancelled_at' => now(),
                'cancel_reason' => $reason,
            ]);
            Audit::log('purchases.cancelled', $purchase, reason: $reason);

            return $purchase->refresh();
        });
    }

    /** An MRP or selling price the confirm set on the product goes back, unless someone changed it again since. */
    private function restorePrices(int $productId, int $batchId, int $userId): void
    {
        $changes = DB::table('product_price_history')
            ->where('product_id', $productId)->where('batch_id', $batchId)->where('source', 'purchase')
            ->whereIn('field', ['mrp', 'selling_price'])->orderBy('id')->get();
        if ($changes->isEmpty()) {
            return;
        }
        $product = Product::withTrashed()->whereKey($productId)->lockForUpdate()->first();
        if ($product === null) {
            return;
        }
        $restore = [];
        foreach ($changes as $change) {
            $column = $change->field === 'mrp' ? 'mrp_paise' : 'selling_price_paise';
            if ($change->old_paise === null || (int) $product->{$column} !== (int) $change->new_paise) {
                continue; // changed again since: leave it
            }
            $restore[$column] = (int) $change->old_paise;
            DB::table('product_price_history')->insert([
                'product_id' => $productId, 'batch_id' => $batchId, 'field' => $change->field,
                'old_paise' => (int) $change->new_paise, 'new_paise' => (int) $change->old_paise,
                'source' => 'purchase_cancel', 'user_id' => $userId, 'created_at' => now(),
            ]);
        }
        if ($restore !== []) {
            Product::withTrashed()->whereKey($productId)->update($restore + ['version' => $product->version + 1]);
        }
    }
}
