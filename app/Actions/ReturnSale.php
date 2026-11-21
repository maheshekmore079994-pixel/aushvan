<?php

namespace App\Actions;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Sales\ReturnPricer;
use App\Domain\Shared\Audit;
use App\Domain\Shared\NumberSequencer;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Takes goods back against a bill. In one transaction: prices the returned lines from the bill, puts
 * the goods back into the batch they came from (on sale again, or set aside as not for sale), writes
 * the credit note, and either pays the money back or takes it off the customer's udhar.
 *
 * Rules (BUSINESS_WORKFLOWS S10): never more than was sold less what was already returned; expired stock
 * never goes back on sale; medicine goes back on sale only with the restock permission.
 */
final class ReturnSale
{
    public function __construct(
        private ReturnPricer $pricer,
        private StockLedger $stock,
        private CustomerLedger $customers,
        private NumberSequencer $numbers,
    ) {}

    /** @param array<string, mixed> $data validated by the controller */
    public function handle(User $user, Sale $sale, array $data): SaleReturn
    {
        return DB::transaction(function () use ($user, $sale, $data) {
            // Locked so two returns against one bill cannot both pass the quantity check.
            $sale = Sale::whereKey($sale->id)->lockForUpdate()->with('items')->firstOrFail();
            $priced = $this->pricer->price($sale, $data['lines']);
            $total = $priced['totals']['total_paise'];

            $customer = $sale->customer_id ? Customer::withTrashed()->whereKey($sale->customer_id)->lockForUpdate()->first() : null;
            $mode = $data['refund_mode'];
            if ($mode === 'account_credit' && $customer === null) {
                throw new BusinessRuleException('customer_required', 'This bill has no customer account; give the money back instead.');
            }
            if ($mode === 'refund' && $total > 0 && empty($data['refund_method'])) {
                throw new BusinessRuleException('refund_method_required', 'Say how the money is given back.');
            }

            $store = $user->store;
            $return = SaleReturn::create($priced['totals'] + [
                'ulid' => (string) Str::ulid(),
                'store_id' => $store->id,
                'return_no' => $this->numbers->next($store->id, 'sale_return', 'R'.(string) $store->setting('counter_prefix', '')),
                'sale_id' => $sale->id,
                'customer_id' => $customer?->id,
                'return_date' => ShopClock::todayString(),
                'reason' => $data['reason'],
                'refund_mode' => $mode,
                'refund_method' => $mode === 'refund' && $total > 0 ? $data['refund_method'] : null,
                'user_id' => $user->id,
            ]);

            $products = Product::withTrashed()->whereIn('id', array_column($priced['lines'], 'product_id'))->get()->keyBy('id');
            $batches = StockBatch::whereIn('id', array_column($priced['lines'], 'batch_id'))->lockForUpdate()->get()->keyBy('id');

            foreach ($priced['lines'] as $line) {
                $batch = $batches->get($line['batch_id']);
                $bucket = $line['bucket'];
                $approvedBy = null;
                if ($bucket === StockLedger::SELLABLE) {
                    if ($batch->isExpired()) {
                        throw new BusinessRuleException('expired_cannot_restock', "{$line['product_name']}: this batch has expired and cannot go back on sale.", ['line_no' => $line['line_no']]);
                    }
                    if ($products->get($line['product_id'])?->type === Product::TYPE_MEDICAL) {
                        if (! $user->can(Permission::RETURNS_RESTOCK_MEDICINE)) {
                            throw new BusinessRuleException('restock_needs_permission', "{$line['product_name']}: only a pharmacist may put returned medicine back on sale.", ['line_no' => $line['line_no']]);
                        }
                        $approvedBy = $user->id;
                    }
                }

                $return->items()->create(array_diff_key($line, ['line_no' => 1, 'product_name' => 1]) + ['restock_approved_by' => $approvedBy]);
                $this->stock->record($batch, $bucket, $line['qty_base'], 'customer_return', $return, $user->id, $return->return_no);
                $sale->items->firstWhere('id', $line['sale_item_id'])->increment('returned_qty_base', $line['qty_base']);
            }

            if ($total > 0) {
                if ($mode === 'refund') {
                    Payment::create([
                        'ulid' => (string) Str::ulid(),
                        'store_id' => $store->id,
                        'direction' => 'out',
                        'method' => $data['refund_method'],
                        'amount_paise' => $total,
                        'party_type' => $customer?->getMorphClass(),
                        'party_id' => $customer?->id,
                        'payable_type' => $return->getMorphClass(),
                        'payable_id' => $return->id,
                        'reference_no' => $data['reference_no'] ?? null,
                        'business_date' => $return->return_date,
                        'user_id' => $user->id,
                        'note' => $return->return_no,
                        'created_at' => now(),
                    ]);
                } else {
                    $this->customers->post($customer, 'return', 0, $total, $return, $user->id, $return->return_no);
                }
            }

            Audit::log('sales.returned', $return, new: ['sale_id' => $sale->id, 'total_paise' => $total, 'refund_mode' => $mode], reason: $data['reason'], userId: $user->id, storeId: $store->id);

            return $return->refresh();
        });
    }
}
