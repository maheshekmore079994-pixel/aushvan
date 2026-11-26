<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\SupplierLedger;
use App\Domain\Shared\Audit;
use App\Domain\Shared\Money;
use App\Domain\Shared\NumberSequencer;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Domain\Shared\TaxCalculator;
use App\Enums\GstMode;
use App\Exceptions\BusinessRuleException;
use App\Models\Payment;
use App\Models\PurchaseReturn;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Sends stock back to a supplier (expired, damaged, wrong, or simply too much): a debit note with its own
 * number series. Goods leave the chosen bucket of each batch at the batch's own cost; the supplier either
 * owes the shop less (ledger) or hands the money back now (a payment in).
 */
final class ReturnToSupplier
{
    public function __construct(
        private StockLedger $stock,
        private SupplierLedger $suppliers,
        private NumberSequencer $numbers,
    ) {}

    /**
     * @param  array{supplier_id: int, reason: string, settlement: string, refund_method?: ?string, lines: list<array{batch_id: int, bucket: string, qty_base: int}>}  $data
     */
    public function handle(User $user, array $data): PurchaseReturn
    {
        return DB::transaction(function () use ($user, $data) {
            $store = $user->store;
            $supplier = Supplier::where('store_id', $store->id)->lockForUpdate()->findOrFail($data['supplier_id']);
            $interState = $supplier->stateCode() !== null && $store->state_code !== null && $supplier->stateCode() !== $store->state_code;
            $taxInCost = $store->gst_mode !== GstMode::Regular;

            $lines = [];
            $sum = ['taxable_paise' => 0, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0, 'cess_paise' => 0];
            foreach (array_values($data['lines']) as $index => $line) {
                $batch = StockBatch::with(['product.taxRate', 'purchaseItem'])->where('store_id', $store->id)->lockForUpdate()->find($line['batch_id']);
                if ($batch === null || $batch->product === null) {
                    throw new BusinessRuleException('batch_missing', 'A batch on this return no longer exists.', ['line' => $index]);
                }
                // Opening stock (no supplier recorded) may go back to anyone; bought stock only to who sold it.
                if ($batch->supplier_id !== null && $batch->supplier_id !== $supplier->id) {
                    throw new BusinessRuleException('batch_not_from_supplier', 'This batch was not bought from this supplier.', ['line' => $index]);
                }
                $qty = (int) $line['qty_base'];
                $bucket = $line['bucket'];
                $available = $bucket === StockLedger::SELLABLE ? $batch->qty_sellable : $batch->qty_unsellable;
                if ($qty < 1 || $qty > $available) {
                    throw new BusinessRuleException('insufficient_stock', 'Not enough stock in this batch.', ['line' => $index, 'available' => $available]);
                }

                // Cost on a regular shop's batch excludes GST (input credit was claimable); otherwise it already holds it.
                $cost = Rate::amount((string) $batch->cost_rate, $qty);
                // The rate the goods were bought at (the purchase line), not whatever the product says today.
                $ratePercent = $batch->purchaseItem !== null ? (string) $batch->purchaseItem->tax_rate_percent : (string) $batch->product->taxRate->rate_percent;
                $cessPercent = $batch->purchaseItem !== null ? (string) $batch->purchaseItem->cess_percent : (string) $batch->product->taxRate->cess_percent;
                $tax = $taxInCost
                    ? TaxCalculator::fromExclusive($cost, '0', '0', $interState)
                    : TaxCalculator::fromExclusive($cost, $ratePercent, $cessPercent, $interState);

                $lines[] = [
                    'batch' => $batch,
                    'row' => [
                        'line_no' => $index + 1,
                        'product_id' => $batch->product_id,
                        'product_name' => $batch->product->name,
                        'batch_id' => $batch->id,
                        'batch_no' => $batch->batch_no,
                        'bucket' => $bucket,
                        'qty_base' => $qty,
                        'cost_rate' => $batch->cost_rate,
                        'tax_rate_percent' => $taxInCost ? '0' : $ratePercent,
                        'cess_percent' => $taxInCost ? '0' : $cessPercent,
                        'taxable_paise' => $tax->taxablePaise,
                        'cgst_paise' => $tax->cgstPaise,
                        'sgst_paise' => $tax->sgstPaise,
                        'igst_paise' => $tax->igstPaise,
                        'cess_paise' => $tax->cessPaise,
                        'line_total_paise' => $tax->taxablePaise + $tax->taxPaise(),
                    ],
                ];
                foreach (array_keys($sum) as $key) {
                    $sum[$key] += $lines[array_key_last($lines)]['row'][$key];
                }
            }
            if ($lines === []) {
                throw new BusinessRuleException('return_empty', 'Choose at least one batch to return.');
            }

            $before = array_sum($sum);
            $roundOff = Money::roundOffToRupee($before);
            $total = $before + $roundOff;
            $returnNo = $this->numbers->next($store->id, 'purchase_return', 'D');

            $return = PurchaseReturn::create($sum + [
                'ulid' => (string) Str::ulid(),
                'store_id' => $store->id,
                'return_no' => $returnNo,
                'supplier_id' => $supplier->id,
                'return_date' => ShopClock::todayString(),
                'reason' => $data['reason'],
                'round_off_paise' => $roundOff,
                'total_paise' => $total,
                'settlement' => $data['settlement'],
                'refund_method' => $data['settlement'] === 'refund_received' ? $data['refund_method'] : null,
                'user_id' => $user->id,
            ]);

            foreach ($lines as $line) {
                $return->items()->create($line['row']);
                $this->stock->record($line['batch'], $line['row']['bucket'], -$line['row']['qty_base'], 'purchase_return', $return, $user->id, $data['reason']);
            }

            if ($data['settlement'] === 'refund_received') {
                Payment::create([
                    'ulid' => (string) Str::ulid(),
                    'store_id' => $store->id,
                    'direction' => 'in',
                    'method' => $data['refund_method'],
                    'amount_paise' => $total,
                    'party_type' => $supplier->getMorphClass(),
                    'party_id' => $supplier->id,
                    'payable_type' => $return->getMorphClass(),
                    'payable_id' => $return->id,
                    'business_date' => ShopClock::todayString(),
                    'user_id' => $user->id,
                    'note' => $returnNo,
                    'created_at' => now(),
                ]);
            } else {
                $this->suppliers->post($supplier, 'return', 0, $total, $return, $user->id, $returnNo);
            }

            Audit::log('purchases.returned', $return, new: ['return_no' => $returnNo, 'total_paise' => $total, 'supplier_id' => $supplier->id], reason: $data['reason']);

            return $return;
        });
    }
}
