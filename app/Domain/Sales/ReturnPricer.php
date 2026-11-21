<?php

namespace App\Domain\Sales;

use App\Domain\Shared\Money;
use App\Exceptions\BusinessRuleException;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;

/**
 * Works out what a customer gets back for the goods they return, line by line, from the prices
 * actually charged on the bill — never from today's prices. A part return takes its share of every
 * amount on the line; the last part takes whatever is left, so the parts always add up to the line.
 * Across all returns on a bill the money given back can never exceed what the bill was for.
 */
final class ReturnPricer
{
    public const AMOUNTS = ['gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'igst_paise', 'cess_paise', 'line_total_paise', 'cost_paise'];

    /**
     * @param  list<array{line_no: int, qty: int, bucket?: string}>  $wanted  quantities in the unit the line was sold in
     * @return array{lines: list<array<string, mixed>>, totals: array<string, int>}
     */
    public function price(Sale $sale, array $wanted): array
    {
        if ($sale->status !== 'completed') {
            throw new BusinessRuleException('bill_cancelled', 'This bill is cancelled; nothing can be returned against it.');
        }

        $items = $sale->items->keyBy('line_no');
        $earlier = SaleReturnItem::whereIn('sale_item_id', $sale->items->pluck('id'))->get()->groupBy('sale_item_id');

        // The same line named twice in one request is one request for the sum (it must never count twice).
        $merged = [];
        foreach ($wanted as $request) {
            $lineNo = (int) $request['line_no'];
            $merged[$lineNo] = [
                'line_no' => $lineNo,
                'qty' => ($merged[$lineNo]['qty'] ?? 0) + (int) $request['qty'],
                'bucket' => $request['bucket'] ?? $merged[$lineNo]['bucket'] ?? null,
            ];
        }

        $lines = [];
        foreach ($merged as $request) {
            /** @var SaleItem|null $item */
            $item = $items->get($request['line_no']);
            if ($item === null) {
                throw new BusinessRuleException('line_not_on_bill', 'That line is not on this bill.', ['line_no' => $request['line_no']]);
            }
            $qty = $request['qty'];
            if ($qty < 1) {
                continue;
            }
            $qtyBase = $qty * $item->unit_factor;
            $left = $item->qty_base - $item->returned_qty_base;
            if ($qtyBase > $left) {
                throw new BusinessRuleException('return_exceeds_sold', "{$item->product_name}: more than was sold is being returned.", [
                    'line_no' => $item->line_no, 'can_return' => intdiv($left, $item->unit_factor), 'unit_code' => $item->unit_code,
                ]);
            }

            $before = $earlier->get($item->id, collect());
            $final = $qtyBase === $left;
            $line = [
                'sale_item_id' => $item->id,
                'line_no' => $item->line_no,
                'product_id' => $item->product_id,
                'product_name' => $item->product_name,
                'batch_id' => $item->batch_id,
                'unit_code' => $item->unit_code,
                'qty' => $qty,
                'qty_base' => $qtyBase,
                'bucket' => $request['bucket'] ?? 'unsellable',
            ];
            foreach (self::AMOUNTS as $key) {
                $remaining = $item->{$key} - (int) $before->sum($key);
                $line[$key] = $final ? max(0, $remaining) : min(max(0, $remaining), Money::mulDiv($item->{$key}, $qtyBase, $item->qty_base));
            }
            if (! $final) {
                // A part's own figures must foot on the printed note: total = gross − discount = taxable + taxes.
                $line['line_total_paise'] = $line['gross_paise'] - $line['discount_paise'];
                $line['sgst_paise'] = max(0, $line['line_total_paise'] - $line['taxable_paise'] - $line['cgst_paise'] - $line['igst_paise'] - $line['cess_paise']);
            }
            $lines[] = $line;
        }

        if ($lines === []) {
            throw new BusinessRuleException('nothing_to_return', 'Choose at least one item to take back.');
        }

        $sum = fn (string $key) => array_sum(array_column($lines, $key));
        $beforeRounding = $sum('line_total_paise');
        $roundOff = Money::roundOffToRupee($beforeRounding);
        $total = $beforeRounding + $roundOff;

        // Rupee rounding happens per note, so across several part returns the notes could drift from the bill
        // by a rupee. Never give back more than the bill was for; the return that empties the bill gives back
        // exactly what is left of it.
        $givenBack = (int) SaleReturn::where('sale_id', $sale->id)->sum('total_paise');
        $billLeft = max(0, $sale->total_paise - $givenBack);
        $returnedAfter = array_column($lines, 'qty_base', 'sale_item_id');
        $emptiesBill = $sale->items->every(fn (SaleItem $i) => $i->returned_qty_base + ($returnedAfter[$i->id] ?? 0) >= $i->qty_base);
        if ($emptiesBill || $total > $billLeft) {
            $total = $billLeft;
            $roundOff = $total - $beforeRounding;
        }

        return [
            'lines' => $lines,
            'totals' => [
                'gross_paise' => $sum('gross_paise'),
                'discount_paise' => $sum('discount_paise'),
                'taxable_paise' => $sum('taxable_paise'),
                'cgst_paise' => $sum('cgst_paise'),
                'sgst_paise' => $sum('sgst_paise'),
                'igst_paise' => $sum('igst_paise'),
                'cess_paise' => $sum('cess_paise'),
                'round_off_paise' => $roundOff,
                'total_paise' => $total,
                'cost_paise' => $sum('cost_paise'),
            ],
        ];
    }
}
