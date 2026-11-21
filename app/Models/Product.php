<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'store_id', 'type', 'name', 'name_local', 'sku', 'category_id', 'brand', 'manufacturer_id', 'description',
    'hsn_code', 'tax_rate_id', 'mrp_paise', 'selling_price_paise', 'default_discount_percent', 'min_stock_qty',
    'tracks_batch', 'tracks_expiry', 'allow_loose_sale', 'rack_location', 'is_quick_item', 'search_text',
    'is_active', 'version',
])]
class Product extends Model
{
    use SoftDeletes;

    public const TYPE_MEDICAL = 'medical';

    public const TYPE_GENERAL = 'general';

    protected function casts(): array
    {
        return [
            'mrp_paise' => 'integer', 'selling_price_paise' => 'integer', 'min_stock_qty' => 'integer',
            'tracks_batch' => 'boolean', 'tracks_expiry' => 'boolean', 'allow_loose_sale' => 'boolean',
            'is_quick_item' => 'boolean', 'is_active' => 'boolean', 'version' => 'integer',
            'default_discount_percent' => 'decimal:2',
        ];
    }

    public function isMedical(): bool
    {
        return $this->type === self::TYPE_MEDICAL;
    }

    public function units(): HasMany
    {
        return $this->hasMany(ProductUnit::class)->orderBy('factor_to_base');
    }

    public function barcodes(): HasMany
    {
        return $this->hasMany(ProductBarcode::class);
    }

    public function batches(): HasMany
    {
        return $this->hasMany(StockBatch::class);
    }

    public function medicine(): HasOne
    {
        return $this->hasOne(MedicineDetail::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(Category::class);
    }

    public function taxRate(): BelongsTo
    {
        return $this->belongsTo(TaxRate::class);
    }

    public function manufacturer(): BelongsTo
    {
        return $this->belongsTo(Manufacturer::class);
    }

    public function baseUnit(): ProductUnit
    {
        return $this->units->firstWhere('is_base', true);
    }

    /** The unit the MRP is printed on (strip, bottle, packet, kg). */
    public function pricingUnit(): ProductUnit
    {
        return $this->units->firstWhere('is_pricing', true);
    }

    /** Lower-cased text the search box matches against. */
    public static function buildSearchText(?string ...$parts): string
    {
        return mb_strtolower(trim(preg_replace('/\s+/u', ' ', implode(' ', array_filter($parts)))));
    }
}
