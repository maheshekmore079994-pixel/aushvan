<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\Rate;
use App\Models\Product;
use App\Models\StockBatch;

/** Shapes products and batches for the screens. Cost is included only for users allowed to see it. */
final class ProductPresenter
{
    /** One row of a product list. Expects ProductQuery::withStock() and units/medicine/category loaded. */
    public static function row(Product $product): array
    {
        $base = $product->baseUnit();
        $pricing = $product->pricingUnit();

        return [
            'id' => $product->id,
            'type' => $product->type,
            'name' => $product->name,
            'name_local' => $product->name_local,
            'sku' => $product->sku,
            'brand' => $product->brand,
            'category' => $product->category?->name,
            'generic_name' => $product->medicine?->generic_name,
            'strength' => $product->medicine?->strength,
            'schedule' => $product->medicine?->schedule?->code,
            'schedule_set' => ! $product->isMedical() || $product->medicine?->drug_schedule_id !== null,
            'mrp_paise' => $product->mrp_paise,
            'selling_price_paise' => $product->selling_price_paise,
            'base_unit' => $base->unit->code,
            'pricing_unit' => $pricing->unit->code,
            'pack_size' => $pricing->factor_to_base,
            'rack_location' => $product->rack_location,
            'is_active' => $product->is_active,
            'min_stock_qty' => $product->min_stock_qty,
            'stock_qty' => (int) $product->stock_qty,
            'expired_qty' => (int) $product->expired_qty,
            'unsellable_qty' => (int) $product->unsellable_qty,
            'nearest_expiry' => $product->nearest_expiry ? substr((string) $product->nearest_expiry, 0, 10) : null,
            'stock_status' => self::stockStatus((int) $product->stock_qty, $product->min_stock_qty),
        ];
    }

    public static function stockStatus(int $stock, int $minimum): string
    {
        if ($stock <= 0) {
            return 'out';
        }

        return $minimum > 0 && $stock <= $minimum ? 'low' : 'ok';
    }

    /** Full detail for the product page and the edit form. */
    public static function detail(Product $product, bool $showCost): array
    {
        $pricing = $product->pricingUnit();
        $base = $product->baseUnit();
        $medicine = $product->medicine;

        return self::row($product) + [
            'category_id' => $product->category_id,
            'manufacturer' => $product->manufacturer?->name,
            'hsn_code' => $product->hsn_code,
            'tax_rate_id' => $product->tax_rate_id,
            'tax_rate' => $product->taxRate->name,
            'base_unit_id' => $base->unit_id,
            'pack_unit_id' => $pricing->is_base ? null : $pricing->unit_id,
            'allow_loose_sale' => $product->allow_loose_sale,
            'min_stock' => intdiv($product->min_stock_qty, $pricing->factor_to_base),
            'tracks_batch' => $product->tracks_batch,
            'tracks_expiry' => $product->tracks_expiry,
            'is_quick_item' => $product->is_quick_item,
            'version' => $product->version,
            'has_stock_history' => $product->batches->isNotEmpty(),
            'barcodes' => $product->barcodes->map(fn ($b) => [
                'barcode' => $b->barcode,
                'unit' => $b->product_unit_id === $base->id && ! $pricing->is_base ? 'base' : 'pack',
                'is_internal' => $b->is_internal,
            ])->values(),
            'medicine' => $medicine ? [
                'generic_name' => $medicine->generic_name,
                'composition_text' => $medicine->composition_text,
                'strength' => $medicine->strength,
                'dosage_form' => $medicine->dosage_form,
                'drug_schedule_id' => $medicine->drug_schedule_id,
                'is_ndps' => $medicine->is_ndps,
                'storage_instructions' => $medicine->storage_instructions,
            ] : null,
            'batches' => $product->batches
                ->sortBy(fn (StockBatch $b) => [$b->expiry_date?->toDateString() ?? '9999-12-31', $b->id])
                ->map(fn (StockBatch $b) => self::batch($b, $pricing->factor_to_base, $showCost))
                ->values(),
        ];
    }

    public static function batch(StockBatch $batch, int $pricingFactor, bool $showCost): array
    {
        $days = $batch->daysToExpiry();

        $row = [
            'id' => $batch->id,
            'batch_no' => $batch->batch_no,
            'expiry_date' => $batch->expiry_date?->toDateString(),
            'mfg_date' => $batch->mfg_date?->toDateString(),
            'received_date' => $batch->received_date->toDateString(),
            'mrp_paise' => $batch->mrp_paise,
            'selling_price_paise' => $batch->selling_price_paise,
            'qty_sellable' => $batch->qty_sellable,
            'qty_unsellable' => $batch->qty_unsellable,
            'days_to_expiry' => $days,
            'expiry_status' => match (true) {
                $days === null => 'none',
                $days < 0 => 'expired',
                $days <= 30 => 'within_30',
                $days <= 60 => 'within_60',
                $days <= 90 => 'within_90',
                default => 'ok',
            },
        ];

        if ($showCost) {
            // Cost of one pricing unit, and the value of everything left in this batch.
            $row['cost_paise'] = Rate::amount($batch->cost_rate, $pricingFactor);
            $row['value_paise'] = Rate::amount($batch->cost_rate, $batch->qty_sellable + $batch->qty_unsellable);
        }

        return $row;
    }
}
