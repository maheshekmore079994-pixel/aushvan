<?php

namespace App\Domain\Catalog;

use App\Domain\Shared\ShopClock;
use App\Models\Product;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Builder;

/**
 * Product lists with their stock figures, written so the same SQL runs on
 * MySQL and SQLite. Quantities are whole base units, so SUM in SQL is exact.
 */
final class ProductQuery
{
    /** Sellable stock that has not expired. */
    public static function sellableStock(): Builder
    {
        $today = ShopClock::todayString();

        return StockBatch::query()
            ->selectRaw('COALESCE(SUM(qty_sellable), 0)')
            ->whereColumn('stock_batches.product_id', 'products.id')
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhereDate('expiry_date', '>=', $today));
    }

    /** Stock still marked sellable although its date has passed (blocked from sale; needs action). */
    public static function expiredStock(): Builder
    {
        return StockBatch::query()
            ->selectRaw('COALESCE(SUM(qty_sellable), 0)')
            ->whereColumn('stock_batches.product_id', 'products.id')
            ->whereDate('expiry_date', '<', ShopClock::todayString());
    }

    public static function withStock(Builder $products): Builder
    {
        $today = ShopClock::todayString();

        return $products->select('products.*')->addSelect([
            'stock_qty' => self::sellableStock(),
            'expired_qty' => self::expiredStock(),
            'unsellable_qty' => StockBatch::query()
                ->selectRaw('COALESCE(SUM(qty_unsellable), 0)')
                ->whereColumn('stock_batches.product_id', 'products.id'),
            'nearest_expiry' => StockBatch::query()
                ->selectRaw('MIN(expiry_date)')
                ->whereColumn('stock_batches.product_id', 'products.id')
                ->where('qty_sellable', '>', 0)
                ->whereDate('expiry_date', '>=', $today),
        ]);
    }

    /** low = at or under minimum but not zero; out = nothing sellable. */
    public static function whereStockLevel(Builder $products, string $level): Builder
    {
        $stock = self::sellableStock();
        $sql = '('.$stock->toSql().')';

        return match ($level) {
            'out' => $products->whereRaw("{$sql} = 0", $stock->getBindings()),
            'low' => $products->where('min_stock_qty', '>', 0)
                ->whereRaw("{$sql} > 0", $stock->getBindings())
                ->whereRaw("{$sql} <= products.min_stock_qty", $stock->getBindings()),
            default => $products,
        };
    }

    /** Every word typed must appear somewhere in the product's search text. */
    public static function search(Builder $products, string $text): Builder
    {
        foreach (preg_split('/\s+/u', mb_strtolower(trim($text)), -1, PREG_SPLIT_NO_EMPTY) as $word) {
            $products->where('search_text', 'like', '%'.addcslashes($word, '%_\\').'%');
        }

        return $products;
    }

    public static function forStore(int $storeId): Builder
    {
        return Product::query()->where('products.store_id', $storeId);
    }
}
