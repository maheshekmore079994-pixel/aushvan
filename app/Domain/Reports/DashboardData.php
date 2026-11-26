<?php

namespace App\Domain\Reports;

use App\Domain\Backup\BackupFolder;
use App\Domain\Catalog\ProductPresenter;
use App\Domain\Catalog\ProductQuery;
use App\Domain\Shared\ShopClock;
use App\Enums\Permission;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Supplier;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Numbers for the home screen. Each block is included only for users allowed to see it:
 * shop-wide sales need "reports.view", profit needs "reports.financial".
 */
final class DashboardData
{
    public const TREND_DAYS = 14;

    public function __construct(private BackupFolder $backups) {}

    public function for(User $user): array
    {
        $storeId = $user->store_id;
        $today = ShopClock::today();
        $todayString = $today->toDateString();

        $data = [
            'today' => $todayString,
            'mine' => $this->salesTotals($storeId, $todayString, $todayString, $user->id),
            'udhar' => $this->udhar($storeId),
            'low_stock' => $this->lowStock($storeId),
            'expiring' => $this->expiring($storeId),
        ];

        if ($user->can(Permission::PURCHASES_MANAGE)) {
            $data['supplier_dues'] = $this->supplierDues($storeId, $todayString);
        }
        if ($user->can(Permission::EXPENSES_MANAGE) || $user->can(Permission::DAY_CLOSE)) {
            $open = CashSession::where('store_id', $storeId)->where('status', CashSession::OPEN)->orderByDesc('business_date')->first();
            $data['day'] = $open ? ['id' => $open->id, 'status' => $open->status, 'business_date' => $open->business_date->toDateString()] : null;
            $data['expenses_today'] = [
                'total_paise' => (int) Expense::where('store_id', $storeId)->whereNull('cancelled_at')->whereDate('expense_date', $todayString)->sum('amount_paise'),
            ];
        }

        if ($user->can(Permission::SALES_CREATE)) {
            $data['recent_bills'] = Sale::where('store_id', $storeId)->with('customer:id,name')
                ->orderByDesc('id')->limit(6)->get()
                ->map(fn (Sale $sale) => [
                    'id' => $sale->id,
                    'invoice_no' => $sale->invoice_no,
                    'customer' => $sale->customer?->name ?? $sale->buyer_name,
                    'total_paise' => $sale->total_paise,
                    'credit_paise' => $sale->credit_paise,
                    'at' => $sale->completed_at->toIso8601String(),
                ])->all();
        }

        if ($user->can(Permission::REPORTS_VIEW)) {
            $yesterday = $today->copy()->subDay()->toDateString();
            $monthStart = $today->copy()->startOfMonth()->toDateString();
            $lastMonthStart = $today->copy()->subMonthNoOverflow()->startOfMonth()->toDateString();
            $lastMonthSameDay = $today->copy()->subMonthNoOverflow()->toDateString();

            $data['sales'] = [
                'today' => $this->salesTotals($storeId, $todayString, $todayString),
                'yesterday' => $this->salesTotals($storeId, $yesterday, $yesterday),
                'month' => $this->salesTotals($storeId, $monthStart, $todayString),
                // The same number of days of last month, so the comparison is like for like.
                'last_month_to_date' => $this->salesTotals($storeId, $lastMonthStart, $lastMonthSameDay),
            ];
            $data['received_today'] = $this->receivedToday($storeId, $todayString);
            $data['trend'] = $this->trend($storeId);
            $data['split_30'] = $this->typeSplit($storeId, $today->copy()->subDays(29)->toDateString());
            $data['top_products'] = $this->topProducts($storeId, $today->copy()->subDays(29)->toDateString());

            if (! $user->can(Permission::REPORTS_FINANCIAL)) {
                foreach ($data['sales'] as &$block) {
                    unset($block['profit_paise']);
                }
                unset($block);
            }
        }

        if (! $user->can(Permission::REPORTS_FINANCIAL)) {
            unset($data['mine']['profit_paise']);
        }

        if ($user->can(Permission::BACKUP_MANAGE)) {
            $data['backup'] = $this->backups->status($user->store);
        }

        return $data;
    }

    /** @return array{bills: int, sales_paise: int, udhar_paise: int, profit_paise: int} */
    private function salesTotals(int $storeId, string $from, string $to, ?int $userId = null): array
    {
        $row = DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $from)->whereDate('sale_date', '<=', $to)
            ->when($userId, fn ($q, $id) => $q->where('user_id', $id))
            ->selectRaw('COUNT(*) as bills, COALESCE(SUM(total_paise), 0) as total, COALESCE(SUM(credit_paise), 0) as udhar, COALESCE(SUM(taxable_paise), 0) as taxable, COALESCE(SUM(cost_paise), 0) as cost')
            ->first();

        return [
            'bills' => (int) $row->bills,
            'sales_paise' => (int) $row->total,
            'udhar_paise' => (int) $row->udhar,
            'profit_paise' => (int) $row->taxable - (int) $row->cost,
        ];
    }

    /** Money that came in today by method (bill payments and udhar collected). */
    private function receivedToday(int $storeId, string $today): array
    {
        $received = ['cash' => 0, 'upi' => 0, 'card' => 0, 'bank' => 0];
        $rows = DB::table('payments')->where('store_id', $storeId)->where('direction', 'in')
            ->whereDate('business_date', $today)->groupBy('method')
            ->selectRaw('method, SUM(amount_paise) as amount')->get();
        foreach ($rows as $row) {
            $received[$row->method] = (int) $row->amount;
        }

        return $received;
    }

    /** One entry per day, oldest first, including days without a sale. */
    private function trend(int $storeId): array
    {
        $start = ShopClock::today()->subDays(self::TREND_DAYS - 1);
        $byDay = DB::table('sales')->where('store_id', $storeId)->where('status', 'completed')
            ->whereDate('sale_date', '>=', $start->toDateString())
            ->groupBy('sale_date')
            ->selectRaw('sale_date, COUNT(*) as bills, SUM(total_paise) as total')
            ->get()
            ->keyBy(fn ($row) => substr((string) $row->sale_date, 0, 10));

        $trend = [];
        for ($i = 0; $i < self::TREND_DAYS; $i++) {
            $date = $start->copy()->addDays($i)->toDateString();
            $trend[] = ['date' => $date, 'bills' => (int) ($byDay[$date]->bills ?? 0), 'sales_paise' => (int) ($byDay[$date]->total ?? 0)];
        }

        return $trend;
    }

    private function typeSplit(int $storeId, string $since): array
    {
        $split = [Product::TYPE_MEDICAL => 0, Product::TYPE_GENERAL => 0];
        $rows = DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $storeId)->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $since)
            ->groupBy('products.type')
            ->selectRaw('products.type, SUM(sale_items.line_total_paise) as amount')->get();
        foreach ($rows as $row) {
            $split[$row->type] = (int) $row->amount;
        }

        return ['medical_paise' => $split[Product::TYPE_MEDICAL], 'general_paise' => $split[Product::TYPE_GENERAL]];
    }

    private function topProducts(int $storeId, string $since): array
    {
        return DB::table('sale_items')
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sales.store_id', $storeId)->where('sales.status', 'completed')
            ->whereDate('sales.sale_date', '>=', $since)
            ->groupBy('sale_items.product_id', 'products.name', 'products.type')
            ->selectRaw('sale_items.product_id, products.name, products.type, SUM(sale_items.line_total_paise) as amount')
            ->orderByDesc('amount')->limit(5)->get()
            ->map(fn ($row) => ['id' => (int) $row->product_id, 'name' => $row->name, 'type' => $row->type, 'amount_paise' => (int) $row->amount])
            ->all();
    }

    private function udhar(int $storeId): array
    {
        $owing = Customer::where('store_id', $storeId)->where('balance_paise', '>', 0);

        return [
            'total_paise' => (int) (clone $owing)->sum('balance_paise'),
            'customers' => (clone $owing)->count(),
            'top' => (clone $owing)->orderByDesc('balance_paise')->limit(5)->get(['id', 'name', 'village', 'balance_paise'])
                ->map(fn (Customer $c) => ['id' => $c->id, 'name' => $c->name, 'village' => $c->village, 'balance_paise' => $c->balance_paise])
                ->all(),
        ];
    }

    /** What the shop owes its suppliers, how much of it is past its due date, and drafts waiting to be confirmed. */
    private function supplierDues(int $storeId, string $today): array
    {
        $owed = Supplier::where('store_id', $storeId)->where('balance_paise', '>', 0);

        return [
            'total_paise' => (int) (clone $owed)->sum('balance_paise'),
            'suppliers' => (clone $owed)->count(),
            'overdue_paise' => (int) Purchase::where('store_id', $storeId)->where('status', Purchase::CONFIRMED)
                ->whereColumn('paid_paise', '<', 'total_paise')->whereNotNull('due_date')->where('due_date', '<', $today)
                ->selectRaw('COALESCE(SUM(total_paise - paid_paise), 0) as due')->value('due'),
            'drafts' => Purchase::where('store_id', $storeId)->where('status', Purchase::DRAFT)->count(),
        ];
    }

    private function lowStock(int $storeId): array
    {
        $rows = collect();
        foreach (['out', 'low'] as $level) {
            $query = ProductQuery::withStock(ProductQuery::forStore($storeId))->where('is_active', true)->with('units.unit');
            $rows = $rows->concat(ProductQuery::whereStockLevel($query, $level)->orderBy('name')->limit(6)->get());
        }

        return $rows->take(6)->map(fn (Product $p) => [
            'id' => $p->id,
            'name' => $p->name,
            'type' => $p->type,
            'stock_qty' => (int) $p->stock_qty,
            'min_stock_qty' => $p->min_stock_qty,
            'base_unit' => $p->baseUnit()->unit->code,
            'pack_size' => $p->pricingUnit()->factor_to_base,
            'pricing_unit' => $p->pricingUnit()->unit->code,
            'stock_status' => ProductPresenter::stockStatus((int) $p->stock_qty, $p->min_stock_qty),
        ])->values()->all();
    }

    /** Batches already expired or expiring within 30 days, soonest first. */
    private function expiring(int $storeId): array
    {
        return StockBatch::where('store_id', $storeId)->where('qty_sellable', '>', 0)->whereNotNull('expiry_date')
            ->whereDate('expiry_date', '<=', ShopClock::today()->addDays(30)->toDateString())
            ->with('product:id,name,type')
            ->orderBy('expiry_date')->limit(6)->get()
            ->map(fn (StockBatch $batch) => [
                'product_id' => $batch->product_id,
                'name' => $batch->product->name,
                'batch_no' => $batch->batch_no,
                'expiry_date' => $batch->expiry_date->toDateString(),
                'days_left' => $batch->daysToExpiry(),
            ])->all();
    }
}
