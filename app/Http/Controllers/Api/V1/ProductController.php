<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\SaveProduct;
use App\Domain\Catalog\Barcode;
use App\Domain\Catalog\ProductPresenter;
use App\Domain\Catalog\ProductQuery;
use App\Domain\Reports\ReportExporter;
use App\Domain\Shared\Audit;
use App\Enums\Permission;
use App\Http\Controllers\Controller;
use App\Http\Requests\ProductRequest;
use App\Models\Category;
use App\Models\DrugSchedule;
use App\Models\Manufacturer;
use App\Models\Product;
use App\Models\ProductBarcode;
use App\Models\TaxRate;
use App\Models\Unit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductController extends Controller
{
    private const WITH = ['units.unit', 'medicine.schedule', 'category'];

    /** Everything the product form needs for its dropdowns, in one request. */
    public function options(Request $request): JsonResponse
    {
        $storeId = $request->user()->store_id;

        return response()->json([
            'units' => Unit::orderBy('name')->get(['id', 'code', 'name', 'name_local']),
            'tax_rates' => TaxRate::where('is_active', true)->orderBy('rate_percent')->get(['id', 'name', 'rate_percent', 'kind']),
            'drug_schedules' => DrugSchedule::where('is_enabled', true)->orderBy('sort_order')
                ->get(['id', 'code', 'name', 'requires_prescription', 'warning_text']),
            'categories' => Category::where('store_id', $storeId)->orderBy('sort_order')->orderBy('name')
                ->get(['id', 'name', 'name_local', 'scope']),
            'manufacturers' => Manufacturer::where('store_id', $storeId)->orderBy('name')->pluck('name'),
            'dosage_forms' => config('shop.dosage_forms'),
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'search' => ['nullable', 'string', 'max:100'],
            'type' => ['nullable', Rule::in([Product::TYPE_MEDICAL, Product::TYPE_GENERAL])],
            'stock' => ['nullable', Rule::in(['low', 'out'])],
            'category_id' => ['nullable', 'integer'],
            'inactive' => ['nullable', 'boolean'],
            'per_page' => ['nullable', 'integer', 'min:5', 'max:100'],
        ]);
        $storeId = $request->user()->store_id;
        $search = trim((string) ($filters['search'] ?? ''));

        $query = ProductQuery::withStock(ProductQuery::forStore($storeId))->with(self::WITH);

        // A scanned or typed barcode goes straight to its product.
        $byBarcode = $search !== ''
            ? ProductBarcode::where('store_id', $storeId)->where('barcode', $search)->value('product_id')
            : null;

        if ($byBarcode) {
            $query->where('products.id', $byBarcode);
        } elseif ($search !== '') {
            ProductQuery::search($query, $search);
        }

        $query
            ->when($filters['type'] ?? null, fn ($q, $type) => $q->where('type', $type))
            ->when($filters['category_id'] ?? null, fn ($q, $id) => $q->where('category_id', $id))
            ->when(empty($filters['inactive']), fn ($q) => $q->where('is_active', true));

        ProductQuery::whereStockLevel($query, $filters['stock'] ?? '');

        $page = $query->orderBy('name')->paginate($filters['per_page'] ?? 25);

        return response()->json([
            'data' => collect($page->items())->map(fn (Product $p) => ProductPresenter::row($p)),
            'meta' => ['total' => $page->total(), 'page' => $page->currentPage(), 'last_page' => $page->lastPage()],
            'matched_barcode' => (bool) $byBarcode,
        ]);
    }

    public function show(Request $request, Product $product): JsonResponse
    {
        return response()->json($this->detail($request, $product));
    }

    public function store(ProductRequest $request, SaveProduct $saveProduct): JsonResponse
    {
        $product = $saveProduct->handle($request->user(), $request->validated());

        return response()->json($this->detail($request, $product), 201);
    }

    public function update(ProductRequest $request, Product $product, SaveProduct $saveProduct): JsonResponse
    {
        abort_unless($product->store_id === $request->user()->store_id, 404);
        $product->load('units');

        $product = $saveProduct->handle($request->user(), $request->validated(), $product);

        return response()->json($this->detail($request, $product));
    }

    /** Names close to what is being typed, shown under the name field so duplicates are noticed early. */
    public function similar(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'min:3', 'max:150'], 'ignore' => ['nullable', 'integer']]);
        $key = SaveProduct::nameKey($data['name']);

        $matches = ProductQuery::search(ProductQuery::forStore($request->user()->store_id), $data['name'])
            ->when($data['ignore'] ?? null, fn ($q, $id) => $q->where('id', '!=', $id))
            ->limit(5)
            ->get(['id', 'name', 'sku', 'type'])
            ->map(fn (Product $p) => $p->only(['id', 'name', 'sku', 'type']) + ['exact' => SaveProduct::nameKey($p->name) === $key]);

        return response()->json(['data' => $matches]);
    }

    public function export(Request $request): StreamedResponse
    {
        $showCost = $request->user()->can(Permission::STOCK_VIEW_COST);
        $query = ProductQuery::withStock(ProductQuery::forStore($request->user()->store_id))
            ->with(self::WITH + [3 => 'taxRate', 4 => 'barcodes'])
            ->orderBy('name');

        return response()->streamDownload(function () use ($query) {
            $out = fopen('php://output', 'w');
            fwrite($out, "\xEF\xBB\xBF"); // lets Excel read Marathi names
            fputcsv($out, [
                'SKU', 'Type', 'Name', 'Local name', 'Category', 'Brand', 'Generic name', 'Strength', 'Schedule',
                'HSN', 'GST', 'Base unit', 'Pack unit', 'Pack size', 'MRP', 'Selling price', 'Stock (base units)',
                'Minimum stock (base units)', 'Rack', 'Barcodes', 'Active',
            ]);
            $query->chunk(500, function ($products) use ($out) {
                foreach ($products as $p) {
                    fputcsv($out, array_map(ReportExporter::csvSafe(...), [
                        $p->sku, $p->type, $p->name, $p->name_local, $p->category?->name, $p->brand,
                        $p->medicine?->generic_name, $p->medicine?->strength, $p->medicine?->schedule?->code,
                        $p->hsn_code, $p->taxRate->name, $p->baseUnit()->unit->code, $p->pricingUnit()->unit->code,
                        $p->pricingUnit()->factor_to_base, number_format($p->mrp_paise / 100, 2, '.', ''),
                        number_format($p->selling_price_paise / 100, 2, '.', ''), (int) $p->stock_qty, $p->min_stock_qty,
                        $p->rack_location, $p->barcodes->pluck('barcode')->implode(' '), $p->is_active ? 'yes' : 'no',
                    ]));
                }
            });
            fclose($out);
        }, 'products-'.now(config('shop.timezone'))->format('Y-m-d').'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }

    /** What the label printing screen needs for the chosen products: name, price and the barcode to print. */
    public function labels(Request $request): JsonResponse
    {
        $data = $request->validate(['ids' => ['required', 'array', 'min:1', 'max:200'], 'ids.*' => ['integer']]);

        $rows = ProductQuery::forStore($request->user()->store_id)->whereIn('id', $data['ids'])
            ->with(['units.unit', 'barcodes'])->orderBy('name')->get()
            ->map(fn (Product $p) => self::labelRow($p));

        return response()->json(['data' => $rows]);
    }

    /** Gives a product without a barcode a shop-made one, so a label can be printed for it. */
    public function makeBarcode(Request $request, Product $product): JsonResponse
    {
        abort_unless($product->store_id === $request->user()->store_id, 404);
        $product->load(['units.unit', 'barcodes']);

        if ($product->barcodes->isEmpty()) {
            DB::transaction(function () use ($product) {
                $product->barcodes()->create([
                    'store_id' => $product->store_id,
                    'product_unit_id' => $product->pricingUnit()->id,
                    'barcode' => Barcode::internal($product->id),
                    'is_internal' => true,
                ]);
                Audit::log('products.barcode_made', $product);
            });
            $product->load('barcodes');
        }

        return response()->json(self::labelRow($product));
    }

    /** @return array<string, mixed> */
    private static function labelRow(Product $product): array
    {
        $pricing = $product->pricingUnit();
        // The pack's own barcode is preferred; a barcode for the loose unit is the last choice.
        $barcode = $product->barcodes->sortBy(fn ($b) => [$b->product_unit_id === $pricing->id ? 0 : 1, $b->is_internal ? 1 : 0, $b->id])->first();

        return [
            'id' => $product->id,
            'name' => $product->name,
            'name_local' => $product->name_local,
            'type' => $product->type,
            'mrp_paise' => $product->mrp_paise,
            'selling_price_paise' => $product->selling_price_paise,
            'pricing_unit' => $pricing->unit->code,
            'barcode' => $barcode?->barcode,
        ];
    }

    /** @return array<string, mixed> */
    private function detail(Request $request, Product $product): array
    {
        abort_unless($product->store_id === $request->user()->store_id, 404);

        $loaded = ProductQuery::withStock(ProductQuery::forStore($product->store_id))
            ->with([...self::WITH, 'barcodes', 'batches', 'manufacturer', 'taxRate'])
            ->findOrFail($product->id);

        return ProductPresenter::detail($loaded, $request->user()->can(Permission::STOCK_VIEW_COST));
    }
}
