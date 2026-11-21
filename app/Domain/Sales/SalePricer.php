<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Domain\Shared\TaxCalculator;
use App\Enums\GstMode;
use App\Models\Product;
use App\Models\StockBatch;
use App\Models\Store;

/**
 * Works out a bill from what is in the cart: which batches the goods come
 * from, the price of every line, tax, round-off and total. The sale screen
 * shows exactly what this returns, and completing the sale runs it again,
 * so the screen and the saved bill can never disagree.
 *
 * Rules:
 *  - one bill line comes from one batch (each batch can have its own MRP);
 *  - with no batch chosen, the batch that expires first is used (FEFO);
 *  - expired stock is never offered and never accepted;
 *  - prices include tax; tax is worked backwards per line.
 */
final class SalePricer
{
    /**
     * @param  list<array{product_id: int, unit?: string, qty: int, batch_id?: int|null, discount_percent?: string|float|int|null}>  $cart
     * @return array{lines: list<array<string, mixed>>, errors: list<array<string, mixed>>, totals: array<string, int>, doc_kind: string, requires: array{prescription: bool, pharmacist: bool}, batches: array<int, StockBatch>}
     */
    public function price(Store $store, array $cart): array
    {
        $products = Product::where('store_id', $store->id)
            ->whereIn('id', array_column($cart, 'product_id'))
            ->with(['units.unit', 'taxRate', 'medicine.schedule', 'batches' => fn ($q) => $q->where('qty_sellable', '>', 0)])
            ->get()
            ->keyBy('id');

        $chargesTax = $store->gst_mode === GstMode::Regular;
        $left = [];      // batch id => sellable quantity not yet taken by an earlier cart line
        $batches = [];
        $lines = [];
        $errors = [];
        $requires = ['prescription' => false, 'pharmacist' => false];

        foreach ($cart as $index => $item) {
            $product = $products->get($item['product_id']);
            $fail = function (string $code, array $extra = []) use (&$errors, $index, $product) {
                $errors[] = ['index' => $index, 'code' => $code, 'product' => $product?->name] + $extra;
            };

            if ($product === null || ! $product->is_active) {
                $fail('product_unavailable');

                continue;
            }

            $pack = $product->pricingUnit();
            $sellLoose = ($item['unit'] ?? 'pack') === 'base' && $pack->factor_to_base > 1;
            if ($sellLoose && ! $product->allow_loose_sale) {
                $fail('loose_not_allowed');

                continue;
            }
            $soldUnit = $sellLoose ? $product->baseUnit() : $pack;
            $soldFactor = $soldUnit->factor_to_base;
            $wanted = (int) $item['qty'] * $soldFactor;
            $discountPercent = (string) ($item['discount_percent'] ?? '0');

            foreach ($product->batches as $batch) {
                $left[$batch->id] ??= $batch->qty_sellable;
                $batches[$batch->id] = $batch;
            }

            $chosen = $item['batch_id'] ?? null;
            if ($chosen !== null) {
                $batch = $product->batches->firstWhere('id', $chosen);
                if ($batch === null) {
                    $fail('insufficient_stock', ['available' => 0]);

                    continue;
                }
                if ($batch->isExpired()) {
                    $fail('batch_expired', ['batch_no' => $batch->batch_no]);

                    continue;
                }
                $candidates = [$batch];
            } else {
                $candidates = $product->batches
                    ->reject(fn (StockBatch $b) => $b->isExpired())
                    ->sortBy(fn (StockBatch $b) => [$b->expiry_date?->toDateString() ?? '9999-12-31', $b->id])
                    ->all();
            }

            // Whole packs only when selling by the pack: a strip is never made up from two batches.
            $plan = [];
            $remaining = $wanted;
            foreach ($candidates as $batch) {
                $take = min($remaining, intdiv($left[$batch->id], $soldFactor) * $soldFactor);
                if ($take > 0) {
                    $plan[] = [$batch, $take];
                    $remaining -= $take;
                }
                if ($remaining === 0) {
                    break;
                }
            }

            if ($remaining > 0) {
                $fail('insufficient_stock', ['available' => intdiv($wanted - $remaining, $soldFactor), 'unit' => $soldUnit->unit->code]);

                continue;
            }

            $schedule = $product->medicine?->schedule;
            $requires['prescription'] = $requires['prescription'] || (bool) $schedule?->requires_prescription;
            $requires['pharmacist'] = $requires['pharmacist'] || (bool) $schedule?->requires_pharmacist;

            foreach ($plan as [$batch, $take]) {
                $left[$batch->id] -= $take;

                $gross = Money::mulDiv($take, $batch->selling_price_paise, $pack->factor_to_base);
                $discount = Money::percentOf($gross, $discountPercent);
                $net = $gross - $discount;

                $taxed = $chargesTax && $product->taxRate->kind === 'taxable';
                $tax = TaxCalculator::fromInclusive(
                    $net,
                    $taxed ? (string) $product->taxRate->rate_percent : '0',
                    $taxed ? (string) $product->taxRate->cess_percent : '0',
                );

                $lines[] = [
                    'index' => $index,
                    'product_id' => $product->id,
                    'product_name' => $product->name,
                    'hsn_code' => $product->hsn_code,
                    'schedule_code' => $schedule?->code,
                    'batch_id' => $batch->id,
                    'batch_no' => $batch->batch_no,
                    'expiry_date' => $batch->expiry_date?->toDateString(),
                    'cost_rate' => $batch->cost_rate,
                    'unit_code' => $soldUnit->unit->code,
                    'unit_factor' => $soldFactor,
                    'qty' => intdiv($take, $soldFactor),
                    'qty_base' => $take,
                    'mrp_paise' => $batch->mrp_paise,
                    'pack_price_paise' => $batch->selling_price_paise,
                    'pack_factor' => $pack->factor_to_base,
                    'gross_paise' => $gross,
                    'discount_percent' => $discountPercent,
                    'discount_paise' => $discount,
                    'tax_rate_percent' => $taxed ? (string) $product->taxRate->rate_percent : '0',
                    'cess_percent' => $taxed ? (string) $product->taxRate->cess_percent : '0',
                    'tax_kind' => $chargesTax ? $product->taxRate->kind : 'not_charged',
                    'taxable_paise' => $tax->taxablePaise,
                    'cgst_paise' => $tax->cgstPaise,
                    'sgst_paise' => $tax->sgstPaise,
                    'igst_paise' => $tax->igstPaise,
                    'cess_paise' => $tax->cessPaise,
                    'line_total_paise' => $net,
                    'cost_paise' => Rate::amount($batch->cost_rate, $take),
                ];
            }
        }

        $sum = fn (string $key) => array_sum(array_column($lines, $key));
        $beforeRounding = $sum('line_total_paise');
        $roundOff = Money::roundOffToRupee($beforeRounding);

        return [
            'lines' => $lines,
            'errors' => $errors,
            'totals' => [
                'gross_paise' => $sum('gross_paise'),
                'discount_paise' => $sum('discount_paise'),
                'taxable_paise' => $sum('taxable_paise'),
                'cgst_paise' => $sum('cgst_paise'),
                'sgst_paise' => $sum('sgst_paise'),
                'igst_paise' => $sum('igst_paise'),
                'cess_paise' => $sum('cess_paise'),
                'round_off_paise' => $roundOff,
                'total_paise' => $beforeRounding + $roundOff,
                'cost_paise' => $sum('cost_paise'),
            ],
            'doc_kind' => $this->documentKind($store->gst_mode, $lines),
            'requires' => $requires,
            'batches' => $batches,
        ];
    }

    /** @param list<array<string, mixed>> $lines */
    private function documentKind(GstMode $mode, array $lines): string
    {
        if ($mode === GstMode::Unregistered) {
            return 'cash_memo';
        }
        if ($mode === GstMode::Composition) {
            return 'bill_of_supply';
        }

        $taxable = count(array_filter($lines, fn ($line) => $line['tax_kind'] === 'taxable'));

        return match (true) {
            $taxable === count($lines) => 'tax_invoice',
            $taxable === 0 => 'bill_of_supply',
            default => 'invoice_cum_bill_of_supply', // taxable and exempt goods on one bill to a consumer
        };
    }
}
