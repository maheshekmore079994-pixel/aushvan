<?php

namespace App\Actions;

use App\Domain\Catalog\Barcode;
use App\Domain\Shared\Audit;
use App\Exceptions\BusinessRuleException;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Creates or updates a product with its units, barcodes and medicine details.
 *
 * The form describes units simply: a base unit (the smallest thing counted:
 * tablet, piece, gram) and an optional pack unit (strip, box, kg) holding
 * pack_size base units. Prices and minimum stock are given per pricing unit
 * (the pack if there is one, otherwise the base unit).
 */
final class SaveProduct
{
    /** @param array<string, mixed> $data validated by ProductRequest */
    public function handle(User $user, array $data, ?Product $product = null): Product
    {
        return DB::transaction(function () use ($user, $data, $product) {
            $creating = $product === null;
            $isMedical = $data['type'] === Product::TYPE_MEDICAL;

            if (! $creating && isset($data['version']) && (int) $data['version'] !== $product->version) {
                throw new BusinessRuleException('stale_version', 'This product was changed by someone else. Reload and try again.');
            }

            if (empty($data['confirm_duplicate'])) {
                $this->refuseDuplicateName($user->store_id, $data['name'], $product?->id);
            }

            $packUnitId = $data['pack_unit_id'] ?? null;
            $packSize = $packUnitId ? (int) $data['pack_size'] : 1;
            $units = $this->desiredUnits((int) $data['base_unit_id'], $packUnitId ? (int) $packUnitId : null, $packSize);

            $unitsChanged = $creating || $this->currentUnits($product) !== $units;
            if (! $creating && $product->batches()->exists()) {
                if ($unitsChanged || $product->type !== $data['type']) {
                    throw new BusinessRuleException(
                        'units_locked',
                        'Units and product type cannot be changed after stock has been entered. Create a new product instead.',
                    );
                }
            }

            $medicine = $isMedical ? ($data['medicine'] ?? []) : [];
            $manufacturerName = trim((string) ($data['manufacturer'] ?? ''));

            $attributes = [
                'type' => $data['type'],
                'name' => trim($data['name']),
                'name_local' => $data['name_local'] ?? null,
                'category_id' => $data['category_id'] ?? null,
                'brand' => $data['brand'] ?? null,
                'manufacturer_id' => $manufacturerName === '' ? null
                    : Manufacturer::firstOrCreate(['store_id' => $user->store_id, 'name' => $manufacturerName])->id,
                'hsn_code' => $data['hsn_code'] ?? null,
                'tax_rate_id' => $data['tax_rate_id'],
                'mrp_paise' => (int) ($data['mrp_paise'] ?? 0),
                'selling_price_paise' => (int) $data['selling_price_paise'],
                'min_stock_qty' => (int) ($data['min_stock'] ?? 0) * $packSize,
                // Medicines are always tracked by batch and expiry.
                'tracks_batch' => $isMedical,
                'tracks_expiry' => $isMedical || ! empty($data['tracks_expiry']),
                'allow_loose_sale' => $packUnitId !== null && ! empty($data['allow_loose_sale']),
                'rack_location' => $data['rack_location'] ?? null,
                'is_quick_item' => ! empty($data['is_quick_item']),
                'is_active' => $data['is_active'] ?? true,
            ];

            $before = $creating ? null : $product->only(['name', 'mrp_paise', 'selling_price_paise', 'tax_rate_id', 'is_active']);

            if ($creating) {
                $product = Product::create($attributes + ['store_id' => $user->store_id, 'search_text' => '']);
                $product->update(['sku' => ($isMedical ? 'M' : 'G').str_pad((string) $product->id, 5, '0', STR_PAD_LEFT)]);
            } else {
                $product->update($attributes + ['version' => $product->version + 1]);
            }

            if ($unitsChanged) {
                $product->units()->delete(); // barcodes on those units go with them and are re-created below
                foreach ($units as $unit) {
                    $product->units()->create($unit);
                }
            }
            $product->load('units');

            $this->syncBarcodes($product, $data['barcodes'] ?? [], ! empty($data['generate_barcode']));

            if ($isMedical) {
                $product->medicine()->updateOrCreate([], [
                    'generic_name' => $medicine['generic_name'] ?? null,
                    'composition_text' => $medicine['composition_text'] ?? null,
                    'strength' => $medicine['strength'] ?? null,
                    'dosage_form' => $medicine['dosage_form'] ?? null,
                    'drug_schedule_id' => $medicine['drug_schedule_id'] ?? null,
                    'is_ndps' => ! empty($medicine['is_ndps']),
                    'storage_instructions' => $medicine['storage_instructions'] ?? null,
                ]);
            } else {
                $product->medicine()->delete();
            }

            $product->refresh()->load(['barcodes', 'medicine']);
            $product->update(['search_text' => Product::buildSearchText(
                $product->name, $product->name_local, $product->brand, $product->sku,
                $medicine['generic_name'] ?? null, $medicine['composition_text'] ?? null,
            )]);

            $this->recordPriceHistory($product, $before, $user->id);
            Audit::log($creating ? 'products.created' : 'products.updated', $product, $before,
                $product->only(['name', 'mrp_paise', 'selling_price_paise', 'tax_rate_id', 'is_active']));

            return $product->refresh();
        });
    }

    /** Letters and digits only, lower-cased: "Crocin  500 Tab." and "crocin 500 tab" are the same product. */
    public static function nameKey(string $name): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower($name));
    }

    private function refuseDuplicateName(int $storeId, string $name, ?int $ignoreId): void
    {
        $key = self::nameKey($name);
        $firstWord = mb_strtolower(preg_split('/\s+/u', trim($name))[0] ?? '');

        $matches = Product::where('store_id', $storeId)
            ->when($ignoreId, fn ($q) => $q->where('id', '!=', $ignoreId))
            ->where('search_text', 'like', '%'.addcslashes($firstWord, '%_\\').'%')
            ->limit(300)
            ->get(['id', 'name', 'sku', 'type'])
            ->filter(fn (Product $p) => self::nameKey($p->name) === $key)
            ->values();

        if ($matches->isNotEmpty()) {
            throw new BusinessRuleException(
                'possible_duplicate',
                'A product with this name already exists.',
                ['duplicates' => $matches->map->only(['id', 'name', 'sku', 'type'])->all()],
            );
        }
    }

    /** @return list<array{unit_id: int, factor_to_base: int, is_base: bool, is_pricing: bool}> */
    private function desiredUnits(int $baseUnitId, ?int $packUnitId, int $packSize): array
    {
        $units = [['unit_id' => $baseUnitId, 'factor_to_base' => 1, 'is_base' => true, 'is_pricing' => $packUnitId === null]];
        if ($packUnitId !== null) {
            $units[] = ['unit_id' => $packUnitId, 'factor_to_base' => $packSize, 'is_base' => false, 'is_pricing' => true];
        }

        return $units;
    }

    /** @return list<array{unit_id: int, factor_to_base: int, is_base: bool, is_pricing: bool}> */
    private function currentUnits(Product $product): array
    {
        return $product->units->map(fn ($u) => [
            'unit_id' => $u->unit_id, 'factor_to_base' => $u->factor_to_base, 'is_base' => $u->is_base, 'is_pricing' => $u->is_pricing,
        ])->values()->all();
    }

    /** @param list<array{barcode: string, unit?: string}> $barcodes */
    private function syncBarcodes(Product $product, array $barcodes, bool $generate): void
    {
        $wanted = [];
        foreach ($barcodes as $row) {
            $unit = ($row['unit'] ?? 'pack') === 'base' ? $product->baseUnit() : $product->pricingUnit();
            $wanted[trim($row['barcode'])] = $unit->id;
        }

        foreach ($product->barcodes()->get() as $existing) {
            if (! array_key_exists($existing->barcode, $wanted) && ! ($existing->is_internal && $generate)) {
                $existing->delete();
            } elseif (isset($wanted[$existing->barcode])) {
                $existing->update(['product_unit_id' => $wanted[$existing->barcode]]);
                unset($wanted[$existing->barcode]);
            }
        }

        foreach ($wanted as $barcode => $unitId) {
            $product->barcodes()->create(['store_id' => $product->store_id, 'product_unit_id' => $unitId, 'barcode' => $barcode]);
        }

        if ($generate && ! $product->barcodes()->where('is_internal', true)->exists()) {
            $product->barcodes()->create([
                'store_id' => $product->store_id,
                'product_unit_id' => $product->pricingUnit()->id,
                'barcode' => Barcode::internal($product->id),
                'is_internal' => true,
            ]);
        }
    }

    /** @param array<string, mixed>|null $before */
    private function recordPriceHistory(Product $product, ?array $before, int $userId): void
    {
        foreach (['mrp' => 'mrp_paise', 'selling_price' => 'selling_price_paise'] as $field => $column) {
            $old = $before[$column] ?? null;
            if ($old !== $product->{$column}) {
                DB::table('product_price_history')->insert([
                    'product_id' => $product->id,
                    'field' => $field,
                    'old_paise' => $old,
                    'new_paise' => $product->{$column},
                    'source' => $before === null ? 'product_created' : 'product_edit',
                    'user_id' => $userId,
                    'created_at' => now(),
                ]);
            }
        }
    }
}
