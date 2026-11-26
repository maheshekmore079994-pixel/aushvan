<?php

namespace App\Domain\Purchases;

use App\Domain\Shared\Money;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Domain\Shared\TaxCalculator;
use App\Enums\GstMode;
use App\Models\Product;
use App\Models\Store;
use App\Models\Supplier;

/**
 * Prices the lines of a supplier invoice the way the paper invoice does: rate × billed quantity, less the
 * line discount, plus GST on top (supplier rates are before tax). Free quantity costs nothing on the invoice
 * but shares the cost: cost per base unit = line cost ÷ (billed + free). When the shop cannot claim input
 * credit (composition or unregistered), the GST paid is part of the cost.
 *
 * Used by the preview (what the screen shows) and by the draft save, so the stored lines are the server's
 * figures, never the screen's.
 */
final class PurchasePricer
{
    /**
     * @param  list<array<string, mixed>>  $lines  product_id, unit (pack|base), qty, free_qty, rate_paise, discount_percent,
     *                                             tax_rate_percent (null = product's), batch_no, expiry_date, mfg_date,
     *                                             mrp_paise (null = product's), selling_price_paise (null = keep product's)
     * @return array{lines: list<array<string, mixed>>, errors: list<array<string, mixed>>, totals: array<string, int>, inter_state: bool}
     */
    public function price(Store $store, ?Supplier $supplier, array $lines, int $otherChargesPaise = 0): array
    {
        $interState = $supplier?->stateCode() !== null && $store->state_code !== null && $supplier->stateCode() !== $store->state_code;
        $taxInCost = $store->gst_mode !== GstMode::Regular;
        $today = ShopClock::todayString();

        $products = Product::with(['units.unit', 'taxRate'])
            ->where('store_id', $store->id)
            ->whereIn('id', array_values(array_unique(array_map(fn ($l) => (int) ($l['product_id'] ?? 0), $lines))))
            ->get()->keyBy('id');

        $priced = [];
        $errors = [];
        $sum = ['gross_paise' => 0, 'discount_paise' => 0, 'taxable_paise' => 0, 'cgst_paise' => 0, 'sgst_paise' => 0, 'igst_paise' => 0, 'cess_paise' => 0];

        foreach (array_values($lines) as $index => $line) {
            $product = $products->get((int) ($line['product_id'] ?? 0));
            if ($product === null || ! $product->is_active) {
                $errors[] = ['index' => $index, 'code' => 'product_missing', 'product' => null];

                continue;
            }
            $fail = function (string $code) use (&$errors, $index, $product): void {
                $errors[] = ['index' => $index, 'code' => $code, 'product' => $product->name];
            };

            $pricing = $product->pricingUnit();
            $unit = ($line['unit'] ?? 'pack') === 'base' ? $product->baseUnit() : $pricing;
            $qty = (int) ($line['qty'] ?? 0);
            $free = (int) ($line['free_qty'] ?? 0);
            $qtyBase = ($qty + $free) * $unit->factor_to_base;
            if ($qty < 0 || $free < 0 || $qtyBase < 1) {
                $fail('qty_required');

                continue;
            }
            $rate = (int) ($line['rate_paise'] ?? -1);
            if ($rate < 0) {
                $fail('rate_required');

                continue;
            }

            $batchNo = trim((string) ($line['batch_no'] ?? '')) ?: null;
            $expiry = $line['expiry_date'] ?? null;
            if ($product->tracks_batch && $batchNo === null) {
                $fail('batch_required');

                continue;
            }
            if ($product->tracks_expiry && $expiry === null) {
                $fail('expiry_required');

                continue;
            }
            if ($expiry !== null && $expiry < $today) {
                $fail('already_expired');

                continue;
            }

            $mrp = array_key_exists('mrp_paise', $line) && $line['mrp_paise'] !== null ? (int) $line['mrp_paise'] : $product->mrp_paise;
            $selling = array_key_exists('selling_price_paise', $line) && $line['selling_price_paise'] !== null ? (int) $line['selling_price_paise'] : null;
            if ($product->isMedical() && $mrp < 1) {
                $fail('mrp_required');

                continue;
            }
            if ($mrp > 0 && ($selling ?? min($product->selling_price_paise ?: $mrp, $mrp)) > $mrp) {
                $fail('price_above_mrp');

                continue;
            }

            $discountPercent = (string) ($line['discount_percent'] ?? '0');
            $taxPercent = array_key_exists('tax_rate_percent', $line) && $line['tax_rate_percent'] !== null
                ? (string) $line['tax_rate_percent']
                : (string) $product->taxRate->rate_percent;
            $cessPercent = (string) $product->taxRate->cess_percent;

            $gross = $rate * $qty;
            $discount = Money::percentOf($gross, $discountPercent);
            $tax = TaxCalculator::fromExclusive($gross - $discount, $taxPercent, $cessPercent, $interState);
            $lineTotal = $tax->taxablePaise + $tax->taxPaise();
            $costTotal = $tax->taxablePaise + ($taxInCost ? $tax->taxPaise() : 0);

            $priced[] = [
                'index' => $index,
                'product_id' => $product->id,
                'product_name' => $product->name,
                'hsn_code' => $product->hsn_code,
                'unit_code' => $unit->unit->code,
                'unit_factor' => $unit->factor_to_base,
                'qty' => $qty,
                'free_qty' => $free,
                'qty_base' => $qtyBase,
                'rate_paise' => $rate,
                'discount_percent' => $discountPercent,
                'gross_paise' => $gross,
                'discount_paise' => $discount,
                'tax_rate_percent' => $taxPercent,
                'cess_percent' => $cessPercent,
                'taxable_paise' => $tax->taxablePaise,
                'cgst_paise' => $tax->cgstPaise,
                'sgst_paise' => $tax->sgstPaise,
                'igst_paise' => $tax->igstPaise,
                'cess_paise' => $tax->cessPaise,
                'line_total_paise' => $lineTotal,
                'batch_no' => $batchNo,
                'mfg_date' => $line['mfg_date'] ?? null,
                'expiry_date' => $expiry,
                'mrp_paise' => $mrp,
                'selling_price_paise' => $selling,
                'cost_rate' => Rate::perBase($costTotal, $qtyBase),
                // For the screen only (not stored): the cost of one pricing unit and the product's pack size.
                'cost_per_pack_paise' => Rate::amount(Rate::perBase($costTotal, $qtyBase), $pricing->factor_to_base),
                'pack_size' => $pricing->factor_to_base,
                'pricing_unit' => $pricing->unit->code,
                'tracks_batch' => $product->tracks_batch,
                'tracks_expiry' => $product->tracks_expiry,
            ];
            foreach (array_keys($sum) as $key) {
                $sum[$key] += $priced[array_key_last($priced)][$key];
            }
        }

        $beforeRounding = $sum['taxable_paise'] + $sum['cgst_paise'] + $sum['sgst_paise'] + $sum['igst_paise'] + $sum['cess_paise'] + $otherChargesPaise;
        $roundOff = Money::roundOffToRupee($beforeRounding);

        return [
            'lines' => $priced,
            'errors' => $errors,
            'totals' => $sum + [
                'other_charges_paise' => $otherChargesPaise,
                'round_off_paise' => $roundOff,
                'total_paise' => $beforeRounding + $roundOff,
            ],
            'inter_state' => $interState,
        ];
    }

    /** The columns of a priced line that are stored on a purchase_items row. */
    public static function storable(array $line): array
    {
        unset($line['index'], $line['cost_per_pack_paise'], $line['pack_size'], $line['pricing_unit'], $line['tracks_batch'], $line['tracks_expiry']);

        return $line;
    }
}
