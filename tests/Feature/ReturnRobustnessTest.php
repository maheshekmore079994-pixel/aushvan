<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\AdjustStock;
use App\Actions\ReceiveCustomerPayment;
use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Sales\ReturnPricer;
use App\Enums\GstMode;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\DrugSchedule;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

/**
 * Tries to break customer returns and bill cancellation (docs/BUSINESS_WORKFLOWS.md S10):
 * money conservation, over-returns, stock buckets, udhar, same-day cancel, immutability, permissions, reports.
 */
class ReturnRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private User $pharmacist;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-19 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
        $this->pharmacist = $this->userWithRole(RoleName::Pharmacist, ['is_pharmacist' => true]);
        $this->owner->store->update(['gst_mode' => GstMode::Regular, 'gstin' => '27AAAAA0000A1Z5']);
        $this->actingAs($this->cashier);
    }

    // ---- helpers (same style as ReturnsTest) -------------------------------------------------

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function tax(string $name): int
    {
        return TaxRate::where('name', $name)->value('id');
    }

    /** A strip-of-10 medicine at Rs 30 a strip, 5% GST. */
    private function medicine(string $name = 'Crocin', string $expiry = '2028-05', int $strips = 10): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('TAB'), 'pack_unit_id' => $this->unit('STRIP'), 'pack_size' => 10,
            'allow_loose_sale' => true, 'mrp_paise' => 3000, 'selling_price_paise' => 3000,
            'medicine' => ['generic_name' => 'Paracetamol', 'drug_schedule_id' => DrugSchedule::where('code', 'NONE')->value('id')],
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $product, [['qty' => $strips, 'cost_paise' => 2340, 'batch_no' => 'B1',
            'expiry_date' => Carbon::createFromFormat('!Y-m', $expiry)->endOfMonth()->toDateString()]]);

        return $product;
    }

    /** General goods; optional tax rate name and several opening batches. */
    private function general(string $name = 'Dettol Soap', int $pricePaise = 5000, int $stock = 20, string $tax = 'GST 5%', ?array $batches = null): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax($tax),
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => $pricePaise, 'selling_price_paise' => $pricePaise,
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $product, $batches ?? [['qty' => $stock, 'cost_paise' => (int) ($pricePaise * 0.9)]]);

        return $product;
    }

    private function sell(array $lines, array $extra = []): Sale
    {
        $response = $this->postJson('/api/v1/sales', $extra + ['ulid' => (string) Str::ulid(), 'lines' => $lines, 'payments' => []])->assertCreated();

        return Sale::findOrFail($response->json('id'));
    }

    private function cash(int $paise): array
    {
        return ['payments' => [['method' => 'cash', 'amount_paise' => $paise]]];
    }

    private function customer(string $name = 'Ramesh Patil', string $mobile = '9800000010'): Customer
    {
        return Customer::create([
            'ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'name' => $name,
            'mobile' => $mobile, 'search_text' => strtolower($name).' '.$mobile,
        ])->refresh();
    }

    private function return(Sale $sale, array $lines, array $extra = [], ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/sales/{$sale->id}/returns", $extra + ['lines' => $lines, 'reason' => 'Did not need it', 'refund_mode' => 'refund', 'refund_method' => 'cash'], ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
    }

    private function cancel(Sale $sale, string $reason = 'Wrong bill', ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => $reason], ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
    }

    private function sellable(Product $product): int
    {
        return (int) StockBatch::where('product_id', $product->id)->sum('qty_sellable');
    }

    private function unsellable(Product $product): int
    {
        return (int) StockBatch::where('product_id', $product->id)->sum('qty_unsellable');
    }

    private function assertLedgersHealthy(): void
    {
        $this->assertSame([], app(StockLedger::class)->findMismatches(), 'stock cache disagrees with the movement ledger');
        $this->assertSame([], app(CustomerLedger::class)->findMismatches(), 'customer balance disagrees with the ledger');
    }

    // ---- 1. money conservation ---------------------------------------------------------------

    public function test_part_returns_in_every_order_add_up_exactly_to_the_bill_line_and_no_part_is_negative(): void
    {
        $this->actingAs($this->owner); // owner may give a line discount
        $soap = $this->general('Awkward Soap', 3333, 200, 'GST 18%');
        $syrup = $this->general('Odd Syrup', 777, 200, 'GST 5%');
        $ramesh = $this->customer(); // bills go on udhar so the awkward totals need no exact payment

        $orders = [[1, 1, 1, 1], [2, 1, 1], [1, 2, 1], [1, 1, 2], [4], [3, 1], [1, 3], [2, 2]];
        foreach ($orders as $order) {
            $sale = $this->sell([
                ['product_id' => $soap->id, 'qty' => 4, 'discount_percent' => '7.5'],
                ['product_id' => $syrup->id, 'qty' => 4],
            ], ['customer_id' => $ramesh->id]);
            $this->assertSame(1000, $sale->discount_paise); // 7.5% of Rs 133.32
            $items = $sale->items()->get()->keyBy('line_no');

            foreach ($order as $part) {
                $this->return($sale, [['line_no' => 1, 'qty' => $part], ['line_no' => 2, 'qty' => $part]])->assertCreated();
            }
            $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold');

            foreach ($items as $lineNo => $item) {
                $parts = SaleReturnItem::where('sale_item_id', $item->id)->get();
                $this->assertCount(count($order), $parts, 'order '.implode('+', $order).", line $lineNo");
                foreach (ReturnPricer::AMOUNTS as $key) {
                    $this->assertSame($item->{$key}, (int) $parts->sum($key), 'order '.implode('+', $order).", line $lineNo, $key does not add up to the bill line");
                    foreach ($parts as $part) {
                        $this->assertGreaterThanOrEqual(0, $part->{$key}, 'order '.implode('+', $order).", line $lineNo, $key negative on a part");
                    }
                }
                $this->assertSame($item->qty_base, (int) $parts->sum('qty_base'));
                $this->assertSame($item->qty_base, $item->refresh()->returned_qty_base);
                // taxable + taxes = line total on every part, as on the bill line.
                foreach ($parts as $part) {
                    $this->assertSame($part->line_total_paise, $part->taxable_paise + $part->cgst_paise + $part->sgst_paise + $part->igst_paise + $part->cess_paise, 'part is not internally consistent');
                    $this->assertSame($part->line_total_paise, $part->gross_paise - $part->discount_paise);
                }
            }
            // Each credit note's header equals the sum of its lines, and all the notes together give back the bill exactly.
            $notes = SaleReturn::where('sale_id', $sale->id)->get();
            foreach ($notes as $note) {
                foreach (['gross_paise', 'discount_paise', 'taxable_paise', 'cgst_paise', 'sgst_paise', 'cost_paise'] as $key) {
                    $this->assertSame((int) $note->items()->sum($key), $note->{$key});
                }
                $this->assertSame($note->total_paise, (int) $note->items()->sum('line_total_paise') + $note->round_off_paise);
                $this->assertGreaterThanOrEqual(0, $note->total_paise);
            }
            $this->assertSame($sale->total_paise, (int) $notes->sum('total_paise'), 'order '.implode('+', $order).': the notes do not give back exactly the bill');
        }
        // Every bill was given back in full as cash, so the drawer paid out exactly what the eight bills were for.
        $this->assertSame((int) Sale::sum('total_paise'), (int) Payment::where('direction', 'out')->sum('amount_paise'));
        $this->assertSame((int) Sale::sum('total_paise'), $ramesh->refresh()->balance_paise); // the udhar itself is untouched by cash refunds
        $this->assertLedgersHealthy();
    }

    public function test_rounding_gains_on_early_parts_are_taken_off_the_last_return_even_on_another_line(): void
    {
        // Line 1: 3 x Rs 33.50 = 100.50; line 2: 1 x Rs 50. Bill 150.50 -> Rs 151.
        $half = $this->general('Half Soap', 3350);
        $soap = $this->general('Plain Soap', 5000);
        $sale = $this->sell([['product_id' => $half->id, 'qty' => 3], ['product_id' => $soap->id, 'qty' => 1]], $this->cash(15100));

        $parts = [];
        foreach ([1, 1, 1] as $qty) {
            $parts[] = $this->return($sale, [['line_no' => 1, 'qty' => $qty]])->assertCreated()->json('totals.total_paise');
        }
        $this->assertSame([3400, 3400, 3400], $parts); // each 33.50 rounds up: Rs 102 for goods billed at Rs 100.50
        // Recorded behaviour: the Rs 50 soap returned last gives back only Rs 49, so the bill is never over-refunded.
        $last = $this->return($sale, [['line_no' => 2, 'qty' => 1]])->assertCreated();
        $this->assertSame(4900, $last->json('totals.total_paise'));
        $this->assertSame(-100, $last->json('totals.round_off_paise'));
        $this->assertSame(15100, (int) SaleReturn::sum('total_paise'));
        $this->assertSame(15100, (int) Payment::where('direction', 'out')->sum('amount_paise'));
    }

    public function test_total_refunded_over_several_part_returns_never_exceeds_what_the_customer_paid(): void
    {
        // Rs 33.50 x 3 = 100.50 -> bill rounds to Rs 101. Each part 33.50 rounds up to Rs 34 -> 3 x 34 = Rs 102.
        $soap = $this->general('Half Soap', 3350);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 3]], $this->cash(10100));
        $this->assertSame(10100, $sale->total_paise);

        $refunded = 0;
        foreach ([1, 1, 1] as $part) {
            $refunded += $this->return($sale, [['line_no' => 1, 'qty' => $part]])->assertCreated()->json('totals.total_paise');
        }
        $this->assertSame($refunded, (int) Payment::where('direction', 'out')->sum('amount_paise'));
        $this->assertLessThanOrEqual($sale->total_paise, $refunded, 'Refunds (Rs '.($refunded / 100).') exceed what the customer paid (Rs '.($sale->total_paise / 100).')');
    }

    public function test_the_last_part_return_gives_back_whatever_rounding_kept_from_the_earlier_parts(): void
    {
        // Rs 33.33 x 3 = 99.99 -> bill Rs 100; parts 33.33 -> Rs 33, Rs 33, then the rest of the bill: Rs 34.
        $soap = $this->general('Odd Soap', 3333);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 3]], $this->cash(10000));
        $parts = [];
        foreach ([1, 1, 1] as $part) {
            $parts[] = $this->return($sale, [['line_no' => 1, 'qty' => $part]])->assertCreated()->json('totals.total_paise');
        }
        $this->assertSame([3300, 3300, 3400], $parts);
        $this->assertSame(10000, array_sum($parts)); // the customer paid 10000 and gets 10000 back
        $this->assertSame(1, (int) SaleReturn::sum('round_off_paise')); // the bill's own round-off of +1 paisa
    }

    // ---- 2. loose units ----------------------------------------------------------------------

    public function test_loose_tablets_and_strips_of_one_medicine_return_in_the_unit_each_line_was_sold_in(): void
    {
        $crocin = $this->medicine(); // 100 tablets
        $sale = $this->sell([
            ['product_id' => $crocin->id, 'qty' => 3, 'unit' => 'base'],   // line 1: 3 tablets
            ['product_id' => $crocin->id, 'qty' => 2],                     // line 2: 2 strips
        ], $this->cash(6900));
        $this->assertSame(77, $this->sellable($crocin));
        [$loose, $strips] = $sale->items()->get()->all();
        $this->assertSame(['TAB', 1, 3], [$loose->unit_code, $loose->unit_factor, $loose->qty_base]);
        $this->assertSame(['STRIP', 10, 20], [$strips->unit_code, $strips->unit_factor, $strips->qty_base]);

        $this->return($sale, [['line_no' => 1, 'qty' => 4]])->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold')->assertJsonPath('can_return', 3)->assertJsonPath('unit_code', 'TAB');
        $this->return($sale, [['line_no' => 2, 'qty' => 3]])->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold')->assertJsonPath('can_return', 2)->assertJsonPath('unit_code', 'STRIP');

        $r1 = $this->return($sale, [['line_no' => 1, 'qty' => 3]])->assertCreated();
        $r1->assertJsonPath('items.0.qty', 3)->assertJsonPath('items.0.unit_code', 'TAB')->assertJsonPath('totals.total_paise', 900);
        $this->assertSame(3, SaleReturnItem::latest('id')->first()->qty_base);
        $this->assertSame(3, $loose->refresh()->returned_qty_base);

        $r2 = $this->return($sale, [['line_no' => 2, 'qty' => 1]])->assertCreated();
        $r2->assertJsonPath('items.0.qty', 1)->assertJsonPath('items.0.unit_code', 'STRIP')->assertJsonPath('totals.total_paise', 3000);
        $this->assertSame(10, SaleReturnItem::latest('id')->first()->qty_base);
        $this->assertSame(10, $strips->refresh()->returned_qty_base);

        // Medicine returned by a cashier is set aside, not back on sale.
        $this->assertSame(77, $this->sellable($crocin));
        $this->assertSame(13, $this->unsellable($crocin));

        // A loose line of 10 tablets cannot give back 11.
        $sale2 = $this->sell([['product_id' => $crocin->id, 'qty' => 10, 'unit' => 'base']], $this->cash(3000));
        $this->return($sale2, [['line_no' => 1, 'qty' => 11]])->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold')->assertJsonPath('can_return', 10);
        $this->return($sale2, [['line_no' => 1, 'qty' => 10]])->assertCreated();
        $this->assertLedgersHealthy();
    }

    // ---- 3. over-return through duplicates and bad input -------------------------------------

    public function test_the_same_line_twice_in_one_request_cannot_return_more_than_was_sold(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));

        // 2 + 1 = 3 asked back against 2 sold, split over two entries for the same line.
        $response = $this->return($sale, [['line_no' => 1, 'qty' => 2, 'bucket' => 'sellable'], ['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']]);
        $item = $sale->items()->first();

        // Refused either way: as duplicate input (422) or as too much (409).
        $this->assertContains($response->status(), [409, 422], $response->getContent());
        $this->assertSame(0, $item->refresh()->returned_qty_base);
        $this->assertSame(0, SaleReturn::count());

        // The pricer itself, fed duplicates straight (as a future caller might), must still treat them as one request.
        $sale->load('items');
        $this->assertThrows(fn () => app(ReturnPricer::class)->price($sale, [['line_no' => 1, 'qty' => 2], ['line_no' => 1, 'qty' => 1]]), BusinessRuleException::class);
        $priced = app(ReturnPricer::class)->price($sale, [['line_no' => 1, 'qty' => 1], ['line_no' => 1, 'qty' => 1]]);
        $this->assertCount(1, $priced['lines']);
        $this->assertSame(2, $priced['lines'][0]['qty_base']);
        $this->assertSame($item->line_total_paise, $priced['lines'][0]['line_total_paise']);
        $this->assertSame(18, $this->sellable($soap));
        $this->assertSame(0, (int) Payment::where('direction', 'out')->sum('amount_paise'));
    }

    public function test_duplicate_line_entries_can_never_leave_returned_quantity_above_sold_quantity(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $this->return($sale, [['line_no' => 1, 'qty' => 2], ['line_no' => 1, 'qty' => 1]]);

        $item = $sale->items()->first();
        $this->assertLessThanOrEqual($item->qty_base, $item->returned_qty_base, 'returned_qty_base exceeds qty_base');
        $this->assertLessThanOrEqual(20, $this->sellable($soap) + $this->unsellable($soap), 'more stock came back than was ever in the shop');
        $this->assertLessThanOrEqual($sale->total_paise, (int) Payment::where('direction', 'out')->sum('amount_paise'), 'refunded more than the bill');
    }

    public function test_bad_lines_are_refused_with_422_or_409_never_500(): void
    {
        $soap = $this->general();
        $brush = $this->general('Brush', 2000);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2], ['product_id' => $brush->id, 'qty' => 1]], $this->cash(12000));
        $body = fn (array $lines, array $extra = []) => $extra + ['lines' => $lines, 'reason' => 'x', 'refund_mode' => 'refund', 'refund_method' => 'cash'];
        $post = fn (array $data) => $this->postJson("/api/v1/sales/{$sale->id}/returns", $data, ['Idempotency-Key' => (string) Str::ulid()]);

        $post($body([['line_no' => 1, 'qty' => -1]]))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1.5]]))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 'two']]))->assertUnprocessable();
        $post($body([['line_no' => 0, 'qty' => 1]]))->assertUnprocessable();
        $post($body([['line_no' => 'a', 'qty' => 1]]))->assertUnprocessable();
        $post($body([['line_no' => 1]]))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1, 'bucket' => 'garbage']]))->assertUnprocessable();
        $post($body([]))->assertUnprocessable();
        $post($body(array_map(fn ($i) => ['line_no' => $i + 1, 'qty' => 0], range(0, 100))))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1], ['line_no' => 1, 'qty' => 1]]))->assertUnprocessable(); // the same line twice
        $post($body([['line_no' => 1, 'qty' => 1]], ['refund_mode' => 'cheque']))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1]], ['refund_method' => 'gold']))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1]], ['reason' => '']))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1]], ['reason' => str_repeat('x', 301)]))->assertUnprocessable();
        $post($body([['line_no' => 1, 'qty' => 1]], ['reference_no' => str_repeat('x', 51)]))->assertUnprocessable();

        $post($body([['line_no' => 7, 'qty' => 1]]))->assertStatus(409)->assertJsonPath('code', 'line_not_on_bill')->assertJsonPath('line_no', 7);
        $post($body([['line_no' => 1, 'qty' => 0], ['line_no' => 2, 'qty' => 0]]))->assertStatus(409)->assertJsonPath('code', 'nothing_to_return');
        $post($body([['line_no' => 1, 'qty' => 1000000]]))->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold');
        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", ['lines' => [['line_no' => 1, 'qty' => 0]]])->assertStatus(409)->assertJsonPath('code', 'nothing_to_return');
        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", ['lines' => [['line_no' => 1, 'qty' => -3]]])->assertUnprocessable();

        // A zero line beside a real one is simply ignored.
        $post($body([['line_no' => 2, 'qty' => 0], ['line_no' => 1, 'qty' => 1]]))->assertCreated()->assertJsonCount(1, 'items')->assertJsonPath('items.0.line_no', 1);
        $this->assertSame(0, $sale->items()->where('line_no', 2)->value('returned_qty_base'));

        // Nothing above changed anything but the one real return.
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(1, $sale->items()->first()->returned_qty_base);
        $this->assertLedgersHealthy();
    }

    public function test_same_key_replays_the_first_answer_and_a_fresh_key_for_more_than_remains_is_refused(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));

        $first = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'k-a')->assertCreated();
        // Same key, different body: the stored answer comes back and nothing new is made.
        $this->return($sale, [['line_no' => 1, 'qty' => 2]], [], 'k-a')->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('return_no', $first->json('return_no'));
        $this->assertSame(1, SaleReturn::count());

        $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'k-b')->assertCreated();
        $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'k-c')->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold')->assertJsonPath('can_return', 0);
        // A refused request frees its key: the same key may be retried later.
        $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'k-c')->assertStatus(409);
        $this->assertSame(2, SaleReturn::count());
        $this->assertSame(2, $sale->items()->first()->returned_qty_base);

        // A key already used on one bill cannot be used on another.
        $other = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->return($other, [['line_no' => 1, 'qty' => 1]], [], 'k-a')->assertUnprocessable()->assertJsonPath('code', 'idempotency_key_reused');
        $this->assertSame(0, $other->items()->first()->returned_qty_base);
    }

    // ---- 4. bucket rules ---------------------------------------------------------------------

    public function test_bucket_rules_for_expired_medicine_general_goods_and_the_owner(): void
    {
        $old = $this->medicine('Old Syrup', '2026-11');
        $crocin = $this->medicine('Crocin');
        $soap = $this->general();
        $saleOld = $this->sell([['product_id' => $old->id, 'qty' => 2]], $this->cash(6000));
        $saleCrocin = $this->sell([['product_id' => $crocin->id, 'qty' => 3]], $this->cash(9000));
        $saleSoap = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));

        Carbon::setTestNow(Carbon::parse('2026-12-01 10:00', 'Asia/Kolkata')); // the Old Syrup batch expired yesterday

        // Expired: not even the owner can put it back on sale; set aside is fine.
        $this->actingAs($this->owner);
        $this->return($saleOld, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertStatus(409)->assertJsonPath('code', 'expired_cannot_restock')->assertJsonPath('line_no', 1);
        $this->assertSame(80, $this->sellable($old));
        $this->return($saleOld, [['line_no' => 1, 'qty' => 1, 'bucket' => 'unsellable']])->assertCreated();
        $this->return($saleOld, [['line_no' => 1, 'qty' => 1]])->assertCreated(); // the default bucket is unsellable
        $this->assertSame(80, $this->sellable($old));
        $this->assertSame(20, $this->unsellable($old));

        // Medicine back on sale: owner yes (holds every permission), cashier no, pharmacist yes.
        $this->return($saleCrocin, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $this->assertSame($this->owner->id, SaleReturnItem::latest('id')->first()->restock_approved_by);
        $this->actingAs($this->cashier);
        $this->return($saleCrocin, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertStatus(409)->assertJsonPath('code', 'restock_needs_permission');
        $this->actingAs($this->pharmacist);
        $this->return($saleCrocin, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $this->assertSame(90, $this->sellable($crocin));

        // General goods: a cashier may put them back on sale, with no approver recorded.
        $this->actingAs($this->cashier);
        $this->return($saleSoap, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $this->assertNull(SaleReturnItem::latest('id')->first()->restock_approved_by);
        $this->return($saleSoap, [['line_no' => 1, 'qty' => 1, 'bucket' => 'unsellable']])->assertCreated();
        $this->assertSame(19, $this->sellable($soap));
        $this->assertSame(1, $this->unsellable($soap));

        // Every movement points at its credit note and carries the bucket it went to.
        $movements = InventoryMovement::where('type', 'customer_return')->get();
        $this->assertCount(6, $movements);
        $this->assertTrue($movements->every(fn ($m) => $m->source_type === (new SaleReturn)->getMorphClass() && $m->qty_delta > 0));
        $this->assertLedgersHealthy();
    }

    // ---- 5. udhar ----------------------------------------------------------------------------

    public function test_account_credit_on_a_bill_already_paid_off_turns_the_balance_into_an_advance(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 6]], ['customer_id' => $ramesh->id]); // Rs 300 udhar
        app(ReceiveCustomerPayment::class)->handle($this->cashier, $ramesh, 30000, 'cash');
        $this->assertSame(0, $ramesh->refresh()->balance_paise);

        $this->return($sale, [['line_no' => 1, 'qty' => 2]], ['refund_mode' => 'account_credit'])->assertCreated()->assertJsonPath('customer.balance_paise', -10000);
        // The shop now owes the customer Rs 100 (an advance). Nothing refuses this; recorded as current behaviour.
        $this->assertSame(-10000, $ramesh->refresh()->balance_paise);
        $this->assertSame(0, Payment::where('direction', 'out')->count());
        $this->assertLedgersHealthy();
    }

    public function test_refund_on_an_udhar_bill_pays_cash_out_and_leaves_the_udhar_ledger_alone(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 6]], ['customer_id' => $ramesh->id] + $this->cash(10000)); // Rs 200 udhar
        $entriesBefore = CustomerLedgerEntry::count();

        $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertCreated()->assertJsonPath('customer.balance_paise', 20000);
        $this->assertSame(20000, $ramesh->refresh()->balance_paise);
        $this->assertSame($entriesBefore, CustomerLedgerEntry::count());
        $out = Payment::where('direction', 'out')->firstOrFail();
        $this->assertSame([5000, 'cash', $ramesh->id, (new Customer)->getMorphClass()], [$out->amount_paise, $out->method, $out->party_id, $out->party_type]);
        $this->assertSame((new SaleReturn)->getMorphClass(), $out->payable_type);

        // account_credit on a walk-in bill has no account to credit.
        $walkIn = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->return($walkIn, [['line_no' => 1, 'qty' => 1]], ['refund_mode' => 'account_credit'])->assertStatus(409)->assertJsonPath('code', 'customer_required');
        $this->assertSame(0, $walkIn->items()->first()->returned_qty_base);
        $this->assertLedgersHealthy();
    }

    public function test_account_credit_still_posts_to_a_deactivated_customer(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], ['customer_id' => $ramesh->id]);
        $ramesh->update(['is_active' => false]);

        // Recorded as current behaviour: the account is inactive but the credit note still reduces its balance.
        $this->return($sale, [['line_no' => 1, 'qty' => 1]], ['refund_mode' => 'account_credit'])->assertCreated();
        $this->assertSame(5000, $ramesh->refresh()->balance_paise);
        $this->assertLedgersHealthy();
    }

    // ---- 6. cancel ---------------------------------------------------------------------------

    public function test_cancel_mirrors_every_split_payment_and_restores_stock_and_balance_exactly(): void
    {
        $soap = $this->general();
        $crocin = $this->medicine();
        $ramesh = $this->customer();
        $this->sell([['product_id' => $soap->id, 'qty' => 1]], ['customer_id' => $ramesh->id]); // an older udhar of Rs 50 stays
        $balanceBefore = $ramesh->refresh()->balance_paise;
        $soapBefore = $this->sellable($soap);
        $crocinBefore = $this->sellable($crocin);

        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2], ['product_id' => $crocin->id, 'qty' => 1, 'unit' => 'base']], [
            'customer_id' => $ramesh->id,
            'payments' => [['method' => 'upi', 'amount_paise' => 5000, 'reference_no' => 'UPI9'], ['method' => 'cash', 'amount_paise' => 3000]],
        ]); // Rs 103: 80 paid, 23 udhar
        $this->assertSame($balanceBefore + 2300, $ramesh->refresh()->balance_paise);

        $this->actingAs($this->owner);
        $this->cancel($sale)->assertOk();

        $this->assertSame($soapBefore, $this->sellable($soap));
        $this->assertSame($crocinBefore, $this->sellable($crocin));
        $this->assertSame($balanceBefore, $ramesh->refresh()->balance_paise);

        $outs = Payment::where('direction', 'out')->orderBy('id')->get();
        $this->assertCount(2, $outs);
        $this->assertSame([['upi', 5000, 'UPI9'], ['cash', 3000, null]], $outs->map(fn ($p) => [$p->method, $p->amount_paise, $p->reference_no])->all());
        $this->assertTrue($outs->every(fn ($p) => $p->payable_type === (new Sale)->getMorphClass() && $p->payable_id === $sale->id && $p->party_id === $ramesh->id));

        $reversal = CustomerLedgerEntry::where('type', 'reversal')->firstOrFail();
        $this->assertSame(2300, $reversal->credit_paise);
        $this->assertNotNull($reversal->reverses_id);
        $this->assertSame('sale', CustomerLedgerEntry::find($reversal->reverses_id)->type);

        $cancels = InventoryMovement::where('type', 'sale_cancel')->get();
        $this->assertSame([2, 1], $cancels->pluck('qty_delta')->all());
        $this->assertTrue($cancels->every(fn ($m) => $m->source_id === $sale->id && $m->bucket === 'sellable'));

        // The number stays with the cancelled bill; the next bill gets a new one.
        $this->actingAs($this->cashier);
        $next = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->assertNotSame($sale->invoice_no, $next->invoice_no);
        $this->assertSame('cancelled', $sale->refresh()->status);
        $this->assertSame(1, Sale::where('invoice_no', $sale->invoice_no)->count());
        $this->assertLedgersHealthy();
    }

    public function test_cancel_is_refused_once_the_shop_day_has_turned_even_when_the_server_day_has_not(): void
    {
        $soap = $this->general();
        $this->actingAs($this->owner);
        Carbon::setTestNow(Carbon::parse('2026-11-19 23:50', 'Asia/Kolkata'));
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->assertSame('2026-11-19', $sale->sale_date->toDateString());

        Carbon::setTestNow(Carbon::parse('2026-11-20 00:10', 'Asia/Kolkata')); // still 18:40 UTC on the 19th
        $this->assertSame('2026-11-19', now()->utc()->toDateString());
        $this->cancel($sale)->assertStatus(409)->assertJsonPath('code', 'cancel_same_day_only');
        $this->assertSame('completed', $sale->refresh()->status);
        $this->assertSame(19, $this->sellable($soap));

        // The other way round: late in the shop day while UTC has moved on is still the same shop day.
        Carbon::setTestNow(Carbon::parse('2026-11-20 05:40', 'Asia/Kolkata')); // 00:10 UTC on the 20th
        $late = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        Carbon::setTestNow(Carbon::parse('2026-11-20 23:59', 'Asia/Kolkata'));
        $this->cancel($late)->assertOk();
        $this->assertLedgersHealthy();
    }

    public function test_cancel_refusals_leave_the_bill_and_stock_untouched(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 3]], $this->cash(15000));

        // No sales.cancel for a cashier or a pharmacist.
        $this->cancel($sale)->assertForbidden();
        $this->actingAs($this->pharmacist);
        $this->cancel($sale)->assertForbidden();

        // Another store's owner never sees it.
        $stranger = User::factory()->create(['store_id' => Store::create(['name' => 'Other'])->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger->refresh());
        $this->cancel($sale)->assertNotFound();

        $this->actingAs($this->owner);
        $this->cancel($sale, '')->assertUnprocessable();
        $this->cancel($sale, str_repeat('x', 301))->assertUnprocessable();
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'x'])->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
        $this->assertSame('completed', $sale->refresh()->status);
        $this->assertSame(17, $this->sellable($soap));

        // Any return, even one set-aside piece, blocks cancellation for good.
        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'unsellable']])->assertCreated();
        $this->cancel($sale)->assertStatus(409)->assertJsonPath('code', 'has_returns');
        $this->getJson("/api/v1/sales/{$sale->id}")->assertJsonPath('can_cancel', false);

        // A cancelled bill: not twice, nothing returned against it, not even a preview.
        $other = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->cancel($other)->assertOk();
        $this->cancel($other)->assertStatus(409)->assertJsonPath('code', 'bill_cancelled');
        $this->return($other, [['line_no' => 1, 'qty' => 1]])->assertStatus(409)->assertJsonPath('code', 'bill_cancelled');
        $this->postJson("/api/v1/sales/{$other->id}/returns/preview", ['lines' => [['line_no' => 1, 'qty' => 1]]])->assertStatus(409)->assertJsonPath('code', 'bill_cancelled');
        $this->assertSame(1, InventoryMovement::where('type', 'sale_cancel')->count());
        $this->assertSame(1, Payment::where('direction', 'out')->where('payable_type', (new Sale)->getMorphClass())->count());
        $this->assertLedgersHealthy();
    }

    // ---- 7. immutability ---------------------------------------------------------------------

    public function test_finalised_documents_refuse_edits_and_deletes(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertCreated();
        $return = SaleReturn::firstOrFail();
        $returnItem = SaleReturnItem::firstOrFail();
        $item = SaleItem::firstOrFail();

        $this->assertThrows(fn () => $return->update(['total_paise' => 1]), LogicException::class);
        $this->assertThrows(fn () => $return->update(['reason' => 'changed my mind']), LogicException::class);
        $this->assertThrows(fn () => $return->delete(), LogicException::class);
        $this->assertThrows(fn () => $returnItem->update(['qty' => 5]), LogicException::class);
        $this->assertThrows(fn () => $returnItem->update(['line_total_paise' => 1]), LogicException::class);
        $this->assertThrows(fn () => $returnItem->delete(), LogicException::class);
        $this->assertThrows(fn () => $sale->update(['total_paise' => 1]), LogicException::class);
        $this->assertThrows(fn () => $sale->update(['customer_id' => null, 'status' => 'cancelled']), LogicException::class);
        $this->assertThrows(fn () => $sale->delete(), LogicException::class);
        $this->assertThrows(fn () => $item->update(['qty' => 1]), LogicException::class);
        $this->assertThrows(fn () => $item->update(['line_total_paise' => 1]), LogicException::class);
        $this->assertThrows(fn () => $item->update(['returned_qty_base' => 0]), LogicException::class); // never downwards
        $this->assertThrows(fn () => $item->update(['returned_qty_base' => 2, 'qty' => 2]), LogicException::class);
        $this->assertThrows(fn () => $item->delete(), LogicException::class);

        $this->assertSame(5000, $return->refresh()->total_paise);
        $this->assertSame(1, $item->refresh()->returned_qty_base);
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(1, SaleReturnItem::count());
    }

    // ---- 8. permissions ----------------------------------------------------------------------

    public function test_return_endpoints_need_their_permissions_and_hide_other_shops(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $return = $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertCreated()->json('id');
        $lines = ['lines' => [['line_no' => 1, 'qty' => 1]]];

        $nobody = User::factory()->create(['store_id' => $this->owner->store_id]);
        $this->actingAs($nobody->refresh());
        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", $lines)->assertForbidden();
        $this->postJson("/api/v1/sales/{$sale->id}/returns", $lines + ['reason' => 'x', 'refund_mode' => 'refund', 'refund_method' => 'cash'], ['Idempotency-Key' => 'n-1'])->assertForbidden();
        $this->getJson("/api/v1/returns/{$return}")->assertForbidden();
        $this->cancel($sale)->assertForbidden();

        $manager = $this->userWithRole(RoleName::StoreManager); // no sales.create, no sales.return
        $this->actingAs($manager);
        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", $lines)->assertForbidden();
        $this->getJson("/api/v1/returns/{$return}")->assertForbidden();

        $this->actingAs($this->cashier);
        $this->getJson("/api/v1/returns/{$return}")->assertOk()->assertJsonPath('sale.invoice_no', $sale->invoice_no);

        $stranger = User::factory()->create(['store_id' => Store::create(['name' => 'Other'])->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger->refresh());
        $this->getJson("/api/v1/returns/{$return}")->assertNotFound();
        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", $lines)->assertNotFound();

        $this->assertSame(1, SaleReturn::count());
    }

    // ---- 9. audit ----------------------------------------------------------------------------

    public function test_returns_and_cancellations_are_audited_with_their_reason(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $wrong = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->return($sale, [['line_no' => 1, 'qty' => 1]], ['reason' => 'Torn wrapper'])->assertCreated();
        $this->actingAs($this->owner);
        $this->cancel($wrong, 'Rang up twice')->assertOk();

        $returned = AuditLog::where('action', 'sales.returned')->firstOrFail();
        $this->assertSame('Torn wrapper', $returned->reason);
        $this->assertSame($this->cashier->id, $returned->user_id);
        $this->assertSame([(new SaleReturn)->getMorphClass(), SaleReturn::first()->id], [$returned->subject_type, $returned->subject_id]);
        $this->assertSame(['sale_id' => $sale->id, 'total_paise' => 5000, 'refund_mode' => 'refund'], $returned->new_values);

        $cancelled = AuditLog::where('action', 'sales.cancelled')->firstOrFail();
        $this->assertSame('Rang up twice', $cancelled->reason);
        $this->assertSame($this->owner->id, $cancelled->user_id);
        $this->assertSame([(new Sale)->getMorphClass(), $wrong->id], [$cancelled->subject_type, $cancelled->subject_id]);
        $this->assertSame('cancelled', $cancelled->new_values['status']);
        $this->assertSame(5000, $cancelled->new_values['total_paise']);
    }

    // ---- 10. reports -------------------------------------------------------------------------

    public function test_reports_show_credit_notes_hide_cancelled_bills_and_list_the_cancel_movements(): void
    {
        $soap = $this->general();
        $this->actingAs($this->owner);
        $kept = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $wrong = $this->sell([['product_id' => $soap->id, 'qty' => 4]], $this->cash(20000));
        $this->cancel($wrong)->assertOk();
        $this->return($kept, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']], ['reason' => 'Torn wrapper'])->assertCreated();
        $period = 'from=2026-11-19&to=2026-11-19';

        $returns = $this->getJson("/api/v1/reports/returns?$period")->assertOk();
        $returns->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.total', 5000)->assertJsonPath('rows.0.reason', 'Torn wrapper')->assertJsonPath('rows.0.bill_no', $kept->invoice_no)->assertJsonPath('totals.total', 5000);
        $this->assertSame(238, $returns->json('rows.0.tax'));
        $csv = $this->getJson("/api/v1/reports/returns/export?$period&format=csv")->assertOk();
        $this->assertStringContainsString($kept->invoice_no, $csv->streamedContent() ?: $csv->getContent());

        $bills = $this->getJson("/api/v1/reports/bills?$period")->assertOk();
        $this->assertSame([$kept->invoice_no], array_column($bills->json('rows'), 'bill_no'));
        // The sales-by-day row counts the kept bill only; the cancelled bill's cash in was mirrored by cash out, but the
        // report sums direction=in only, so cash still reads Rs 300 (Rs 100 kept + Rs 200 cancelled). Recorded here.
        $byDay = $this->getJson("/api/v1/reports/sales_by_day?$period")->assertOk();
        $byDay->assertJsonPath('totals.bills', 1)->assertJsonPath('totals.total', 10000);
        $this->assertSame(30000, $byDay->json('totals.cash'));

        $moves = $this->getJson("/api/v1/reports/stock_movements?$period")->assertOk()->json('rows');
        $types = array_count_values(array_column($moves, 'movement'));
        $this->assertSame(1, $types['sale_cancel'] ?? 0);
        $this->assertSame(1, $types['customer_return'] ?? 0);
        $this->assertSame(2, $types['sale'] ?? 0);
        $cancelRow = collect($moves)->firstWhere('movement', 'sale_cancel');
        $this->assertSame(4, $cancelRow['change']);
        $this->assertSame($wrong->invoice_no, $cancelRow['note']);
    }

    // ---- extra attacks -----------------------------------------------------------------------

    public function test_a_product_deleted_after_the_sale_can_still_be_returned_to_its_batch(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $soap->delete();
        $this->assertNotNull(Product::withTrashed()->find($soap->id)->deleted_at);

        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated()->assertJsonPath('items.0.product_name', 'Dettol Soap');
        $this->assertSame(19, $this->sellable($soap));
        $this->getJson('/api/v1/returns/'.SaleReturn::first()->id)->assertOk();
        $this->assertLedgersHealthy();
    }

    public function test_a_bill_whose_customer_was_deleted_can_still_be_refunded_and_cancelled(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], ['customer_id' => $ramesh->id] + $this->cash(5000)); // Rs 50 udhar
        $toCancel = $this->sell([['product_id' => $soap->id, 'qty' => 1]], ['customer_id' => $ramesh->id]); // Rs 50 udhar
        $this->assertSame(10000, $ramesh->refresh()->balance_paise);
        $ramesh->delete();

        // Cash back works; the credit note still names the bill's customer.
        $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertCreated();
        $this->assertSame($ramesh->id, SaleReturn::first()->customer_id, 'credit note lost the customer of the bill');

        // Cancelling the udhar bill must reverse the ledger, not blow up because the customer row is trashed.
        $this->actingAs($this->owner);
        $this->cancel($toCancel)->assertOk();
        $reversal = CustomerLedgerEntry::where('type', 'reversal')->firstOrFail();
        $this->assertSame(5000, $reversal->credit_paise);
        $this->assertSame(5000, $reversal->balance_after_paise, 'the reversal row says Rs 50 was credited but the balance written on it did not move');
        $this->assertSame(5000, Customer::withTrashed()->find($ramesh->id)->balance_paise, 'the trashed customer still owes the cancelled bill');
        $this->assertLedgersHealthy();
    }

    public function test_account_credit_on_a_bill_whose_customer_was_deleted_really_moves_the_balance(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], ['customer_id' => $ramesh->id]); // Rs 100 udhar
        $ramesh->delete();

        $response = $this->return($sale, [['line_no' => 1, 'qty' => 1]], ['refund_mode' => 'account_credit']);
        if ($response->status() === 409) {
            // Refusing is acceptable too, as long as nothing was written.
            $response->assertJsonPath('code', 'customer_required');
            $this->assertSame(0, SaleReturn::count());
            $this->assertSame(10000, Customer::withTrashed()->find($ramesh->id)->balance_paise);

            return;
        }
        $response->assertCreated();
        $entry = CustomerLedgerEntry::where('type', 'return')->firstOrFail();
        $this->assertSame(5000, $entry->credit_paise);
        $this->assertSame(5000, $entry->balance_after_paise, 'the return row says Rs 50 was credited but the balance written on it did not move');
        $this->assertSame(5000, Customer::withTrashed()->find($ramesh->id)->balance_paise);
        $this->assertLedgersHealthy();
    }

    public function test_return_still_restocks_a_batch_that_was_written_off_to_zero_in_between(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $batch = StockBatch::where('product_id', $soap->id)->firstOrFail();
        app(AdjustStock::class)->handle($this->owner, $batch, AdjustStock::WRITE_OFF_DAMAGE, 18, 'sellable', 'Water damage');
        $this->assertSame(0, $this->sellable($soap));

        $this->return($sale, [['line_no' => 1, 'qty' => 2, 'bucket' => 'sellable']])->assertCreated();
        $this->assertSame(2, $batch->refresh()->qty_sellable);
        $this->assertSame(2, InventoryMovement::where('type', 'customer_return')->value('balance_after'));
        $this->assertLedgersHealthy();
    }

    public function test_two_lines_of_one_product_from_different_batches_each_go_back_to_their_own_batch(): void
    {
        $soap = $this->general('Two Batch Soap', 5000, 0, 'GST 5%', [
            ['qty' => 3, 'cost_paise' => 4000, 'batch_no' => 'A'],
            ['qty' => 20, 'cost_paise' => 4500, 'batch_no' => 'B'],
        ]);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 5]], $this->cash(25000));
        $items = $sale->items()->get();
        $this->assertCount(2, $items, 'the sale should have split over two batches');
        $this->assertNotSame($items[0]->batch_id, $items[1]->batch_id);
        $before = StockBatch::where('product_id', $soap->id)->pluck('qty_sellable', 'id')->all();

        $this->return($sale, [['line_no' => 2, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $this->return($sale, [['line_no' => 1, 'qty' => $items[0]->qty, 'bucket' => 'sellable']])->assertCreated();

        foreach ($items as $item) {
            $expected = $before[$item->batch_id] + ($item->line_no === 1 ? $item->qty_base : 1);
            $this->assertSame($expected, StockBatch::find($item->batch_id)->qty_sellable, "batch of line {$item->line_no}");
            $this->assertSame($item->batch_id, SaleReturnItem::where('sale_item_id', $item->id)->value('batch_id'));
        }
        // The two lines carry different costs; each credit note carries the cost of its own batch.
        $this->assertSame($items[0]->cost_paise, (int) SaleReturnItem::where('sale_item_id', $items[0]->id)->sum('cost_paise')); // MySQL sums come back as strings
        $this->assertLedgersHealthy();
    }
}
