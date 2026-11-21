<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\CancelSale;
use App\Actions\ReturnSale;
use App\Domain\Sales\ReturnPricer;
use App\Http\Controllers\Controller;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/** Customer returns (credit notes) and bill cancellation. */
class SaleReturnController extends Controller
{
    /** @return array<string, mixed> */
    private static function lineRules(): array
    {
        return [
            'lines' => ['required', 'array', 'min:1', 'max:100'],
            'lines.*.line_no' => ['required', 'integer', 'min:1', 'distinct'],
            'lines.*.qty' => ['required', 'integer', 'min:0', 'max:1000000'],
            'lines.*.bucket' => ['nullable', Rule::in(['sellable', 'unsellable'])],
        ];
    }

    /** What the customer would get back for these lines. Nothing is saved. */
    public function preview(Request $request, Sale $sale, ReturnPricer $pricer): JsonResponse
    {
        abort_unless($sale->store_id === $request->user()->store_id, 404);
        $data = $request->validate(self::lineRules());
        $priced = $pricer->price($sale->load('items'), $data['lines']);

        return response()->json([
            'lines' => array_map(fn (array $l) => array_diff_key($l, ['cost_paise' => 1, 'sale_item_id' => 1, 'batch_id' => 1]), $priced['lines']),
            'totals' => array_diff_key($priced['totals'], ['cost_paise' => 1]),
        ]);
    }

    public function store(Request $request, Sale $sale, ReturnSale $action): JsonResponse
    {
        abort_unless($sale->store_id === $request->user()->store_id, 404);
        $data = $request->validate(self::lineRules() + [
            'reason' => ['required', 'string', 'max:300'],
            'refund_mode' => ['required', Rule::in(SaleReturn::MODES)],
            'refund_method' => ['nullable', Rule::in(Payment::METHODS)],
            'reference_no' => ['nullable', 'string', 'max:50'],
        ]);

        return response()->json($this->creditNote($action->handle($request->user(), $sale, $data)), 201);
    }

    public function show(Request $request, SaleReturn $saleReturn): JsonResponse
    {
        abort_unless($saleReturn->store_id === $request->user()->store_id, 404);

        return response()->json($this->creditNote($saleReturn));
    }

    public function cancel(Request $request, Sale $sale, CancelSale $action): JsonResponse
    {
        abort_unless($sale->store_id === $request->user()->store_id, 404);
        $data = $request->validate(['reason' => ['required', 'string', 'max:300']]);
        $action->handle($request->user(), $sale, $data['reason']);

        return response()->json(['id' => $sale->id, 'status' => 'cancelled']);
    }

    /** Everything needed to show and print a credit note. @return array<string, mixed> */
    public static function creditNote(SaleReturn $return): array
    {
        $return->load(['items.saleItem', 'sale:id,invoice_no,sale_date', 'customer', 'user', 'payments']);

        return [
            'id' => $return->id,
            'return_no' => $return->return_no,
            'return_date' => $return->return_date->toDateString(),
            'created_at' => $return->created_at->toIso8601String(),
            'sale' => ['id' => $return->sale->id, 'invoice_no' => $return->sale->invoice_no, 'sale_date' => $return->sale->sale_date->toDateString()],
            'customer' => $return->customer ? ['id' => $return->customer->id, 'name' => $return->customer->name, 'balance_paise' => $return->customer->balance_paise] : null,
            'reason' => $return->reason,
            'refund_mode' => $return->refund_mode,
            'refund_method' => $return->refund_method,
            'by' => $return->user->name,
            'items' => $return->items->map(fn (SaleReturnItem $i) => [
                'line_no' => $i->saleItem->line_no,
                'product_name' => $i->saleItem->product_name,
                'batch_no' => $i->saleItem->batch_no,
                'unit_code' => $i->unit_code,
                'qty' => $i->qty,
                'bucket' => $i->bucket,
                'line_total_paise' => $i->line_total_paise,
                'tax_rate_percent' => (string) $i->saleItem->tax_rate_percent,
            ])->values(),
            'totals' => $return->only(['gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise', 'round_off_paise', 'total_paise']),
        ];
    }
}
