<?php

namespace App\Domain\Reports;

use App\Domain\Catalog\ProductPresenter;
use App\Domain\Catalog\ProductQuery;
use App\Domain\Shared\Rate;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Models\Customer;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Every report is a table: columns (with a type the screen and the exports format by), rows and totals.
 * The screen, CSV, Excel and PDF all show the same result, so they can never disagree.
 *
 * Money is integer paise and quantities are whole base units, so SUM in SQL is exact.
 * Cost rates are decimals and are multiplied in PHP through Rate, never summed in SQL.
 *
 * Column types: text, date, datetime, money, int, percent, label (value translated under `prefix`).
 */
final class ReportRunner
{
    public const MAX_ROWS = 5000;

    /** key => group and the filters the report understands. */
    public const REPORTS = [
        'sales_by_day' => ['group' => 'sales', 'filters' => ['period']],
        'sales_by_item' => ['group' => 'sales', 'filters' => ['period', 'type']],
        'bills' => ['group' => 'sales', 'filters' => ['period']],
        'gst_summary' => ['group' => 'sales', 'filters' => ['period']],
        'returns' => ['group' => 'sales', 'filters' => ['period']],
        'stock_summary' => ['group' => 'stock', 'filters' => ['type']],
        'reorder' => ['group' => 'stock', 'filters' => ['type']],
        'expiry' => ['group' => 'stock', 'filters' => ['days', 'type']],
        'slow_moving' => ['group' => 'stock', 'filters' => ['days', 'type']],
        'stock_movements' => ['group' => 'stock', 'filters' => ['period', 'type']],
        'udhar_outstanding' => ['group' => 'udhar', 'filters' => []],
    ];

    private User $user;

    private int $storeId;

    /**
     * @param  array{from?: string|null, to?: string|null, type?: string|null, days?: int|string|null}  $filters
     * @return array{key: string, columns: list<array<string, string>>, rows: list<array<string, mixed>>, totals: array<string, int>, filters: array<string, mixed>, truncated: bool}
     */
    public function run(User $user, string $key, array $filters): array
    {
        $this->user = $user;
        $this->storeId = $user->store_id;
        $accepts = self::REPORTS[$key]['filters'];

        $today = ShopClock::today();
        $resolved = [];
        if (in_array('period', $accepts, true)) {
            $resolved['from'] = $filters['from'] ?? $today->copy()->startOfMonth()->toDateString();
            $resolved['to'] = $filters['to'] ?? $today->toDateString();
        }
        if (in_array('type', $accepts, true)) {
            $resolved['type'] = $filters['type'] ?? null;
        }
        if (in_array('days', $accepts, true)) {
            $resolved['days'] = (int) ($filters['days'] ?? ($key === 'expiry' ? 90 : 60));
        }

        [$columns, $rows, $totalKeys] = match ($key) {
            'sales_by_day' => $this->salesByDay($resolved),
            'sales_by_item' => $this->salesByItem($resolved),
            'bills' => $this->bills($resolved),
            'gst_summary' => $this->gstSummary($resolved),
            'returns' => $this->returns($resolved),
            'stock_summary' => $this->stockSummary($resolved),
            'reorder' => $this->reorder($resolved),
            'expiry' => $this->expiry($resolved),
            'slow_moving' => $this->slowMoving($resolved),
            'stock_movements' => $this->stockMovements($resolved),
            'udhar_outstanding' => $this->udharOutstanding(),
        };

        $truncated = count($rows) > self::MAX_ROWS;
        $rows = array_slice($rows, 0, self::MAX_ROWS);

        // A column the user may not see (profit, cost value) is removed from the rows too, not just from the headings.
        $visible = array_flip(array_column($columns, 'key'));
        $rows = array_map(fn (array $row) => array_intersect_key($row, $visible), $rows);

        $totals = [];
        foreach ($totalKeys as $totalKey) {
            if (in_array($totalKey, array_column($columns, 'key'), true)) {
                $totals[$totalKey] = array_sum(array_column($rows, $totalKey));
            }
        }

        return ['key' => $key, 'columns' => $columns, 'rows' => $rows, 'totals' => $totals, 'filters' => $resolved, 'truncated' => $truncated];
    }

    private function seesProfit(): bool
    {
        return $this->user->can(Permission::REPORTS_FINANCIAL);
    }

    private function seesCost(): bool
    {
        return $this->user->can(Permission::STOCK_VIEW_COST);
    }

    /** @param list<array{0: string, 1: string, 2?: string}|null> $columns */
    private static function columns(array $columns): array
    {
        return array_values(array_map(
            fn (array $c) => ['key' => $c[0], 'type' => $c[1]] + (isset($c[2]) ? ['prefix' => $c[2]] : []),
            array_filter($columns),
        ));
    }

    private static function day(mixed $value): ?string
    {
        return $value === null ? null : substr((string) $value, 0, 10);
    }

    // ---- Sales ------------------------------------------------------------------------------

    private function salesByDay(array $f): array
    {
        $days = [];
        $blank = ['bills' => 0, 'total' => 0, 'cash' => 0, 'upi' => 0, 'card' => 0, 'bank' => 0, 'udhar' => 0, 'udhar_received' => 0, 'profit' => 0];

        $sales = DB::table('sales')->where('store_id', $this->storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $f['from'])->whereDate('sale_date', '<=', $f['to'])
            ->groupBy('sale_date')
            ->selectRaw('sale_date, COUNT(*) as bills, SUM(total_paise) as total, SUM(credit_paise) as udhar, SUM(taxable_paise) as taxable, SUM(cost_paise) as cost')
            ->get();
        foreach ($sales as $row) {
            $days[self::day($row->sale_date)] = ['bills' => (int) $row->bills, 'total' => (int) $row->total, 'udhar' => (int) $row->udhar,
                'profit' => (int) $row->taxable - (int) $row->cost] + $blank;
        }

        $saleType = (new Sale)->getMorphClass();
        $payments = DB::table('payments')->where('store_id', $this->storeId)->where('direction', 'in')
            ->whereDate('business_date', '>=', $f['from'])->whereDate('business_date', '<=', $f['to'])
            ->groupBy('business_date', 'method', 'payable_type')
            ->selectRaw('business_date, method, payable_type, SUM(amount_paise) as amount')
            ->get();
        foreach ($payments as $row) {
            $date = self::day($row->business_date);
            $days[$date] ??= $blank;
            if ($row->payable_type === $saleType) {
                $days[$date][$row->method] += (int) $row->amount;
            } else {
                $days[$date]['udhar_received'] += (int) $row->amount;
            }
        }

        ksort($days);
        $rows = [];
        foreach ($days as $date => $values) {
            $rows[] = ['date' => $date] + $values;
        }

        return [
            self::columns([
                ['date', 'date'], ['bills', 'int'], ['total', 'money'], ['cash', 'money'], ['upi', 'money'], ['card', 'money'],
                ['bank', 'money'], ['udhar', 'money'], ['udhar_received', 'money'], $this->seesProfit() ? ['profit', 'money'] : null,
            ]),
            $rows,
            ['bills', 'total', 'cash', 'upi', 'card', 'bank', 'udhar', 'udhar_received', 'profit'],
        ];
    }

    private function salesByItem(array $f): array
    {
        $items = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $this->storeId)->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $f['from'])->whereDate('sales.sale_date', '<=', $f['to'])
            ->when($f['type'], fn ($q, $type) => $q->where('products.type', $type))
            ->groupBy('sale_items.product_id', 'products.name', 'products.type')
            ->selectRaw('sale_items.product_id, products.name, products.type, SUM(sale_items.qty_base) as qty, SUM(sale_items.line_total_paise) as amount, SUM(sale_items.taxable_paise) as taxable, SUM(sale_items.cost_paise) as cost')
            ->orderByDesc('amount')
            ->limit(self::MAX_ROWS + 1)
            ->get();
        $units = $this->baseUnits($items->pluck('product_id')->all());

        $rows = $items->map(fn ($row) => [
            'product' => $row->name, 'type' => $row->type, 'qty' => (int) $row->qty, 'unit' => $units[$row->product_id] ?? '',
            'amount' => (int) $row->amount, 'profit' => (int) $row->taxable - (int) $row->cost,
        ])->all();

        return [
            self::columns([
                ['product', 'text'], ['type', 'label', 'products.type'], ['qty', 'int'], ['unit', 'label', 'unit'],
                ['amount', 'money'], $this->seesProfit() ? ['profit', 'money'] : null,
            ]),
            $rows,
            ['amount', 'profit'],
        ];
    }

    private function bills(array $f): array
    {
        $rows = Sale::where('store_id', $this->storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $f['from'])->whereDate('sale_date', '<=', $f['to'])
            ->with(['customer:id,name', 'user:id,name'])
            ->orderBy('id')->limit(self::MAX_ROWS + 1)->get()
            ->map(fn (Sale $sale) => [
                'bill_no' => $sale->invoice_no,
                'date' => $sale->sale_date->toDateString(),
                'customer' => $sale->customer?->name ?? $sale->buyer_name ?? '',
                'total' => $sale->total_paise,
                'paid' => $sale->paid_paise,
                'udhar' => $sale->credit_paise,
                'tax' => $sale->cgst_paise + $sale->sgst_paise + $sale->igst_paise + $sale->cess_paise,
                'profit' => $sale->taxable_paise - $sale->cost_paise,
                'by' => $sale->user->name,
            ])->all();

        return [
            self::columns([
                ['bill_no', 'text'], ['date', 'date'], ['customer', 'text'], ['total', 'money'], ['paid', 'money'],
                ['udhar', 'money'], ['tax', 'money'], $this->seesProfit() ? ['profit', 'money'] : null, ['by', 'text'],
            ]),
            $rows,
            ['total', 'paid', 'udhar', 'tax', 'profit'],
        ];
    }

    private function returns(array $f): array
    {
        $rows = SaleReturn::where('store_id', $this->storeId)
            ->whereDate('return_date', '>=', $f['from'])->whereDate('return_date', '<=', $f['to'])
            ->with(['sale:id,invoice_no', 'customer:id,name', 'user:id,name'])
            ->orderBy('id')->limit(self::MAX_ROWS + 1)->get()
            ->map(fn (SaleReturn $r) => [
                'return_no' => $r->return_no,
                'date' => $r->return_date->toDateString(),
                'bill_no' => $r->sale->invoice_no,
                'customer' => $r->customer?->name ?? '',
                'total' => $r->total_paise,
                'tax' => $r->cgst_paise + $r->sgst_paise + $r->igst_paise + $r->cess_paise,
                'refund' => $r->refund_mode,
                'reason' => $r->reason,
                'by' => $r->user->name,
            ])->all();

        return [
            self::columns([
                ['return_no', 'text'], ['date', 'date'], ['bill_no', 'text'], ['customer', 'text'], ['total', 'money'], ['tax', 'money'],
                ['refund', 'label', 'returns.refund_mode'], ['reason', 'text'], ['by', 'text'],
            ]),
            $rows,
            ['total', 'tax'],
        ];
    }

    private function gstSummary(array $f): array
    {
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $this->storeId)->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $f['from'])->whereDate('sales.sale_date', '<=', $f['to'])
            ->groupBy('sale_items.tax_rate_percent')
            ->selectRaw('sale_items.tax_rate_percent as rate, SUM(sale_items.taxable_paise) as taxable, SUM(sale_items.cgst_paise) as cgst, SUM(sale_items.sgst_paise) as sgst, SUM(sale_items.igst_paise) as igst, SUM(sale_items.cess_paise) as cess, SUM(sale_items.line_total_paise) as amount')
            ->orderBy('rate')
            ->get()
            ->map(fn ($row) => [
                'gst_rate' => (string) $row->rate, 'taxable' => (int) $row->taxable, 'cgst' => (int) $row->cgst,
                'sgst' => (int) $row->sgst, 'igst' => (int) $row->igst, 'cess' => (int) $row->cess, 'amount' => (int) $row->amount,
            ])->all();

        return [
            self::columns([['gst_rate', 'percent'], ['taxable', 'money'], ['cgst', 'money'], ['sgst', 'money'], ['igst', 'money'], ['cess', 'money'], ['amount', 'money']]),
            $rows,
            ['taxable', 'cgst', 'sgst', 'igst', 'cess', 'amount'],
        ];
    }

    // ---- Stock ------------------------------------------------------------------------------

    private function products(?string $type)
    {
        return ProductQuery::withStock(ProductQuery::forStore($this->storeId))
            ->where('is_active', true)
            ->when($type, fn ($q, $t) => $q->where('type', $t))
            ->with(['units.unit', 'category'])
            ->orderBy('name')
            ->get();
    }

    /** Value at cost of everything in the shop, per product. @return array<int, int> */
    private function stockValues(): array
    {
        $values = [];
        StockBatch::where('store_id', $this->storeId)
            ->where(fn ($q) => $q->where('qty_sellable', '>', 0)->orWhere('qty_unsellable', '>', 0))
            ->select(['id', 'product_id', 'cost_rate', 'qty_sellable', 'qty_unsellable'])
            ->chunkById(1000, function ($chunk) use (&$values) {
                foreach ($chunk as $batch) {
                    $values[$batch->product_id] = ($values[$batch->product_id] ?? 0)
                        + Rate::amount($batch->cost_rate, $batch->qty_sellable + $batch->qty_unsellable);
                }
            });

        return $values;
    }

    /** @param list<int> $productIds @return array<int, string> product id => base unit code */
    private function baseUnits(array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        return DB::table('product_units')->join('units', 'units.id', '=', 'product_units.unit_id')
            ->whereIn('product_units.product_id', $productIds)->where('product_units.is_base', true)
            ->pluck('units.code', 'product_units.product_id')->all();
    }

    private function stockSummary(array $f): array
    {
        $values = $this->seesCost() ? $this->stockValues() : [];

        $rows = $this->products($f['type'])->map(fn (Product $p) => [
            'product' => $p->name,
            'type' => $p->type,
            'category' => $p->category?->name ?? '',
            'stock' => (int) $p->stock_qty,
            'unit' => $p->baseUnit()->unit->code,
            'min_stock' => $p->min_stock_qty,
            'status' => ProductPresenter::stockStatus((int) $p->stock_qty, $p->min_stock_qty),
            'not_for_sale' => (int) $p->unsellable_qty + (int) $p->expired_qty,
            'rack' => $p->rack_location ?? '',
            'value' => $values[$p->id] ?? 0,
        ])->all();

        return [
            self::columns([
                ['product', 'text'], ['type', 'label', 'products.type'], ['category', 'text'], ['stock', 'int'], ['unit', 'label', 'unit'],
                ['min_stock', 'int'], ['status', 'label', 'stock.status'], ['not_for_sale', 'int'], ['rack', 'text'],
                $this->seesCost() ? ['value', 'money'] : null,
            ]),
            $rows,
            ['value'],
        ];
    }

    private function reorder(array $f): array
    {
        $since = ShopClock::today()->subDays(30)->toDateString();
        $sold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $this->storeId)->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $since)
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, SUM(sale_items.qty_base) as qty')
            ->pluck('qty', 'product_id');

        $rows = $this->products($f['type'])
            ->filter(fn (Product $p) => (int) $p->stock_qty <= 0 || ($p->min_stock_qty > 0 && (int) $p->stock_qty <= $p->min_stock_qty))
            ->map(fn (Product $p) => [
                'product' => $p->name,
                'type' => $p->type,
                'status' => ProductPresenter::stockStatus((int) $p->stock_qty, $p->min_stock_qty),
                'stock' => (int) $p->stock_qty,
                'min_stock' => $p->min_stock_qty,
                'short_by' => max($p->min_stock_qty - (int) $p->stock_qty, 0),
                'sold_30' => (int) ($sold[$p->id] ?? 0),
                'unit' => $p->baseUnit()->unit->code,
            ])
            ->sortBy([['stock', 'asc'], ['sold_30', 'desc']])
            ->values()->all();

        return [
            self::columns([
                ['product', 'text'], ['type', 'label', 'products.type'], ['status', 'label', 'stock.status'], ['stock', 'int'],
                ['min_stock', 'int'], ['short_by', 'int'], ['sold_30', 'int'], ['unit', 'label', 'unit'],
            ]),
            $rows,
            [],
        ];
    }

    private function expiry(array $f): array
    {
        $until = ShopClock::today()->addDays($f['days'])->toDateString();

        $rows = StockBatch::where('stock_batches.store_id', $this->storeId)
            ->where('qty_sellable', '>', 0)->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $until)
            ->whereHas('product', fn ($q) => $q->when($f['type'], fn ($q2, $t) => $q2->where('type', $t)))
            ->with('product.units.unit')
            ->orderBy('expiry_date')->limit(self::MAX_ROWS + 1)->get()
            ->map(fn (StockBatch $batch) => [
                'product' => $batch->product->name,
                'batch_no' => $batch->batch_no ?? '',
                'expiry_date' => $batch->expiry_date->toDateString(),
                'days_left' => $batch->daysToExpiry(),
                'stock' => $batch->qty_sellable,
                'unit' => $batch->product->baseUnit()->unit->code,
                'value' => Rate::amount($batch->cost_rate, $batch->qty_sellable),
            ])->all();

        return [
            self::columns([
                ['product', 'text'], ['batch_no', 'text'], ['expiry_date', 'date'], ['days_left', 'int'], ['stock', 'int'],
                ['unit', 'label', 'unit'], $this->seesCost() ? ['value', 'money'] : null,
            ]),
            $rows,
            ['value'],
        ];
    }

    private function slowMoving(array $f): array
    {
        $cutoff = ShopClock::today()->subDays($f['days'])->toDateString();
        $lastSold = DB::table('sale_items')->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->where('sales.store_id', $this->storeId)->where('sales.status', 'completed')
            ->groupBy('sale_items.product_id')
            ->selectRaw('sale_items.product_id, MAX(sales.sale_date) as last_sold')
            ->pluck('last_sold', 'product_id');
        $values = $this->seesCost() ? $this->stockValues() : [];

        $rows = $this->products($f['type'])
            ->filter(function (Product $p) use ($lastSold, $cutoff) {
                $last = self::day($lastSold[$p->id] ?? null);

                return (int) $p->stock_qty > 0 && ($last === null || $last < $cutoff);
            })
            ->map(fn (Product $p) => [
                'product' => $p->name,
                'type' => $p->type,
                'stock' => (int) $p->stock_qty,
                'unit' => $p->baseUnit()->unit->code,
                'last_sold' => self::day($lastSold[$p->id] ?? null),
                'value' => $values[$p->id] ?? 0,
            ])
            ->sortByDesc('value')->values()->all();

        return [
            self::columns([
                ['product', 'text'], ['type', 'label', 'products.type'], ['stock', 'int'], ['unit', 'label', 'unit'],
                ['last_sold', 'date'], $this->seesCost() ? ['value', 'money'] : null,
            ]),
            $rows,
            ['value'],
        ];
    }

    private function stockMovements(array $f): array
    {
        $zone = config('shop.timezone');
        $from = Carbon::parse($f['from'], $zone)->startOfDay()->utc();
        $to = Carbon::parse($f['to'], $zone)->endOfDay()->utc();

        $rows = InventoryMovement::where('inventory_movements.store_id', $this->storeId)
            ->whereBetween('inventory_movements.created_at', [$from, $to])
            ->join('products', 'products.id', '=', 'inventory_movements.product_id')
            ->when($f['type'], fn ($q, $t) => $q->where('products.type', $t))
            ->select('inventory_movements.*', 'products.name as product_name')
            ->with(['batch:id,batch_no', 'user:id,name'])
            ->orderBy('inventory_movements.id')->limit(self::MAX_ROWS + 1)->get()
            ->map(fn (InventoryMovement $m) => [
                'at' => $m->created_at->toIso8601String(),
                'product' => $m->product_name,
                'batch_no' => $m->batch->batch_no ?? '',
                'movement' => $m->type,
                'change' => $m->qty_delta,
                'balance' => $m->balance_after,
                'by' => $m->user->name,
                'note' => $m->note ?? '',
            ])->all();

        return [
            self::columns([
                ['at', 'datetime'], ['product', 'text'], ['batch_no', 'text'], ['movement', 'label', 'stock.movement'],
                ['change', 'int'], ['balance', 'int'], ['by', 'text'], ['note', 'text'],
            ]),
            $rows,
            [],
        ];
    }

    // ---- Udhar ------------------------------------------------------------------------------

    private function udharOutstanding(): array
    {
        $rows = Customer::where('store_id', $this->storeId)->where('balance_paise', '!=', 0)
            ->orderByDesc('balance_paise')->limit(self::MAX_ROWS + 1)->get()
            ->map(fn (Customer $c) => [
                'customer' => $c->name,
                'mobile' => $c->mobile ?? '',
                'village' => $c->village ?? '',
                'balance' => $c->balance_paise,
                'limit' => $c->credit_limit_paise,
                'last_payment' => $c->last_payment_at?->timezone(config('shop.timezone'))->toDateString(),
            ])->all();

        return [
            self::columns([['customer', 'text'], ['mobile', 'text'], ['village', 'text'], ['balance', 'money'], ['limit', 'money'], ['last_payment', 'date']]),
            $rows,
            ['balance'],
        ];
    }
}
