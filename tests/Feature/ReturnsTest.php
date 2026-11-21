<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Sales\ReturnPricer;
use App\Enums\GstMode;
use App\Enums\RoleName;
use App\Exceptions\BusinessRuleException;
use App\Models\Customer;
use App\Models\DrugSchedule;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\SaleReturn;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Scenario 10 (customer return) and bill cancellation, docs/BUSINESS_WORKFLOWS.md S10. */
class ReturnsTest extends TestCase
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

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function tax(string $name): int
    {
        return TaxRate::where('name', $name)->value('id');
    }

    /** A strip-of-10 medicine at Rs 30 a strip. */
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

    private function general(string $name = 'Dettol Soap', int $pricePaise = 5000, int $stock = 20): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => $pricePaise, 'selling_price_paise' => $pricePaise,
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $product, [['qty' => $stock, 'cost_paise' => (int) ($pricePaise * 0.9)]]);

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

    private function customer(): Customer
    {
        return Customer::create([
            'ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'name' => 'Ramesh Patil',
            'mobile' => '9800000010', 'search_text' => 'ramesh patil 9800000010',
        ])->refresh();
    }

    private function return(Sale $sale, array $lines, array $extra = [], string $key = 'ret-1'): TestResponse
    {
        return $this->postJson("/api/v1/sales/{$sale->id}/returns", $extra + ['lines' => $lines, 'reason' => 'Did not need it', 'refund_mode' => 'refund', 'refund_method' => 'cash'], ['Idempotency-Key' => $key]);
    }

    private function sellable(Product $product): int
    {
        return (int) StockBatch::where('product_id', $product->id)->sum('qty_sellable');
    }

    private function unsellable(Product $product): int
    {
        return (int) StockBatch::where('product_id', $product->id)->sum('qty_unsellable');
    }

    public function test_cash_refund_of_part_of_a_line_puts_general_goods_back_in_stock_and_pays_from_the_bill_prices(): void
    {
        $soap = $this->general(); // Rs 50 each, 5% GST included
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 4]], $this->cash(20000));
        $this->assertSame(16, $this->sellable($soap));

        $response = $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $response->assertJsonPath('totals.total_paise', 5000)->assertJsonPath('refund_mode', 'refund')->assertJsonPath('refund_method', 'cash')
            ->assertJsonPath('items.0.qty', 1)->assertJsonPath('items.0.bucket', 'sellable');
        $this->assertMatchesRegularExpression('/^R26-27\/000001$/', $response->json('return_no'));

        $this->assertSame(17, $this->sellable($soap));
        $this->assertSame(0, $this->unsellable($soap));
        $movement = InventoryMovement::where('type', 'customer_return')->firstOrFail();
        $this->assertSame(1, $movement->qty_delta);
        $this->assertSame('sellable', $movement->bucket);

        $refund = Payment::where('direction', 'out')->firstOrFail();
        $this->assertSame(5000, $refund->amount_paise);
        $this->assertSame('cash', $refund->method);
        $this->assertSame((new SaleReturn)->getMorphClass(), $refund->payable_type);

        // The bill itself is untouched apart from the returned counter; its own total still reads Rs 200.
        $bill = $this->getJson("/api/v1/sales/{$sale->id}")->assertOk();
        $bill->assertJsonPath('totals.total_paise', 20000)->assertJsonPath('items.0.returned_qty', 1)->assertJsonPath('status', 'completed')
            ->assertJsonPath('returns.0.total_paise', 5000)->assertJsonPath('can_cancel', false)->assertJsonPath('can_return', true);

        // The tax on the credit note is the tax that was charged on that part of the line.
        $this->assertSame(238, $response->json('totals.cgst_paise') + $response->json('totals.sgst_paise')); // 5% inside Rs 50
        $this->assertSame([], app(StockLedger::class)->findMismatches());
    }

    public function test_cannot_return_more_than_was_sold_across_several_returns_and_the_last_part_takes_the_remainder(): void
    {
        $soap = $this->general('Odd Soap', 3333); // Rs 33.33: 3 pieces = Rs 99.99 -> bill Rs 100 after round-off
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 3]], $this->cash(10000));

        $first = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'r-1')->assertCreated();
        $second = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'r-2')->assertCreated();
        $this->return($sale, [['line_no' => 1, 'qty' => 2]], [], 'r-3')->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold')->assertJsonPath('can_return', 1);
        $third = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'r-4')->assertCreated();
        $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'r-5')->assertStatus(409)->assertJsonPath('code', 'return_exceeds_sold');

        // Every paisa of the line's taxable value and tax has gone back, no more and no less.
        $sum = fn (string $key) => $first->json("totals.$key") + $second->json("totals.$key") + $third->json("totals.$key");
        $item = $sale->items()->first();
        $this->assertSame($item->taxable_paise, $sum('taxable_paise'));
        $this->assertSame($item->cgst_paise, $sum('cgst_paise'));
        $this->assertSame($item->line_total_paise, $sum('gross_paise') - $sum('discount_paise'));
        $this->assertSame(3, $item->refresh()->returned_qty_base);
        $this->getJson("/api/v1/sales/{$sale->id}")->assertJsonPath('can_return', false);
    }

    public function test_the_same_line_named_twice_in_one_request_counts_once_and_a_duplicate_line_is_refused(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));

        // Validation refuses the duplicate outright …
        $this->return($sale, [['line_no' => 1, 'qty' => 1], ['line_no' => 1, 'qty' => 1]])->assertUnprocessable();
        // … and even if it did not, the pricer treats two mentions as one request for their sum.
        $priced = app(ReturnPricer::class)->price($sale->load('items'), [['line_no' => 1, 'qty' => 1], ['line_no' => 1, 'qty' => 0]]);
        $this->assertCount(1, $priced['lines']);
        $this->assertSame(5000, $priced['totals']['total_paise']);
        try {
            app(ReturnPricer::class)->price($sale->load('items'), [['line_no' => 1, 'qty' => 1], ['line_no' => 1, 'qty' => 1]]);
            $this->fail('two units of a one-unit line were priced');
        } catch (BusinessRuleException $e) {
            $this->assertSame('return_exceeds_sold', $e->ruleCode);
        }
        $this->assertSame(0, SaleReturn::count());
        $this->assertSame(19, $this->sellable($soap));
    }

    public function test_part_returns_never_give_back_more_than_the_bill_and_a_full_return_gives_back_exactly_the_bill(): void
    {
        // Rs 33.50 x 3 = 100.50 -> bill Rs 101 (round-off +0.50). Each third alone would round up to Rs 34.
        $soap = $this->general('Half Soap', 3350);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 3]], $this->cash(10100));
        $a = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'h-1')->assertCreated()->json('totals.total_paise');
        $b = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'h-2')->assertCreated()->json('totals.total_paise');
        $c = $this->return($sale, [['line_no' => 1, 'qty' => 1]], [], 'h-3')->assertCreated()->json('totals.total_paise');
        $this->assertSame(10100, $a + $b + $c);
        $this->assertSame(10100, (int) Payment::where('direction', 'out')->sum('amount_paise'));

        // Rs 33.33 x 3 = 99.99 -> bill Rs 100. Thirds round down to Rs 33; the last one makes up the difference.
        $odd = $this->general('Odd Soap', 3333);
        $sale2 = $this->sell([['product_id' => $odd->id, 'qty' => 3]], $this->cash(10000));
        $parts = [];
        foreach (['o-1', 'o-2', 'o-3'] as $key) {
            $parts[] = $this->return($sale2, [['line_no' => 1, 'qty' => 1]], [], $key)->assertCreated()->json('totals.total_paise');
        }
        $this->assertSame([3300, 3300, 3400], $parts);
        $this->assertSame(10000, array_sum($parts));
    }

    public function test_medicine_goes_to_not_for_sale_unless_a_pharmacist_restocks_it_and_expired_stock_is_never_restocked(): void
    {
        $crocin = $this->medicine();
        $sale = $this->sell([['product_id' => $crocin->id, 'qty' => 2]], $this->cash(6000));

        // A cashier cannot put medicine back on sale.
        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']], [], 'm-1')
            ->assertStatus(409)->assertJsonPath('code', 'restock_needs_permission');
        $this->assertSame(80, $this->sellable($crocin));

        // Set aside: the shop keeps it but it is not for sale.
        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'unsellable']], [], 'm-2')->assertCreated()->assertJsonPath('items.0.bucket', 'unsellable');
        $this->assertSame(80, $this->sellable($crocin));
        $this->assertSame(10, $this->unsellable($crocin));

        // The pharmacist may restock the other strip.
        $this->actingAs($this->pharmacist);
        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']], [], 'm-3')->assertCreated();
        $this->assertSame(90, $this->sellable($crocin));
        $this->assertSame($this->pharmacist->id, SaleReturn::latest('id')->first()->items()->first()->restock_approved_by);

        // Expired after the sale: even a pharmacist cannot put it back on sale.
        $old = $this->medicine('Old Syrup', '2026-11');
        $sale2 = $this->sell([['product_id' => $old->id, 'qty' => 1]], $this->cash(3000));
        Carbon::setTestNow(Carbon::parse('2026-12-02 10:00', 'Asia/Kolkata'));
        $this->return($sale2, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']], [], 'm-4')->assertStatus(409)->assertJsonPath('code', 'expired_cannot_restock');
        $this->return($sale2, [['line_no' => 1, 'qty' => 1, 'bucket' => 'unsellable']], [], 'm-5')->assertCreated();
        $this->assertSame(10, $this->unsellable($old));
    }

    public function test_return_against_an_udhar_bill_reduces_what_the_customer_owes(): void
    {
        $soap = $this->general();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 10]], ['customer_id' => $ramesh->id] + $this->cash(20000)); // owes Rs 300

        $this->return($sale, [['line_no' => 1, 'qty' => 2]], ['refund_mode' => 'account_credit'])->assertCreated()->assertJsonPath('customer.balance_paise', 20000);
        $this->assertSame(20000, $ramesh->refresh()->balance_paise);
        $this->assertSame(0, Payment::where('direction', 'out')->count()); // no cash left the drawer
        $statement = $this->getJson("/api/v1/customers/{$ramesh->id}")->json('entries');
        $this->assertSame('return', $statement[0]['type']);
        $this->assertSame(10000, $statement[0]['credit_paise']);
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());

        // A walk-in bill has no account to credit.
        $walkIn = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->return($walkIn, [['line_no' => 1, 'qty' => 1]], ['refund_mode' => 'account_credit'], 'w-1')->assertStatus(409)->assertJsonPath('code', 'customer_required');
        $this->return($walkIn, [['line_no' => 1, 'qty' => 1]], ['refund_method' => null], 'w-2')->assertStatus(409)->assertJsonPath('code', 'refund_method_required');
    }

    public function test_the_same_return_sent_twice_is_made_once_and_the_preview_saves_nothing(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));

        $this->postJson("/api/v1/sales/{$sale->id}/returns/preview", ['lines' => [['line_no' => 1, 'qty' => 2]]])->assertOk()->assertJsonPath('totals.total_paise', 10000);
        $this->assertSame(0, SaleReturn::count());

        $first = $this->return($sale, [['line_no' => 1, 'qty' => 2]], [], 'same')->assertCreated();
        $this->return($sale, [['line_no' => 1, 'qty' => 2]], [], 'same')->assertCreated()->assertHeader('Idempotency-Replayed', 'true')
            ->assertJsonPath('return_no', $first->json('return_no'));
        $this->assertSame(1, SaleReturn::count());
        $this->assertSame(20, $this->sellable($soap) + $this->unsellable($soap));
        $this->postJson("/api/v1/sales/{$sale->id}/returns", ['lines' => [['line_no' => 1, 'qty' => 1]], 'reason' => 'x', 'refund_mode' => 'refund', 'refund_method' => 'cash'])
            ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
    }

    public function test_cancelling_a_bill_the_same_day_undoes_stock_money_and_udhar_and_keeps_the_number(): void
    {
        $soap = $this->general();
        $crocin = $this->medicine();
        $ramesh = $this->customer();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2], ['product_id' => $crocin->id, 'qty' => 1, 'unit' => 'base']],
            ['customer_id' => $ramesh->id, 'payments' => [['method' => 'upi', 'amount_paise' => 5000, 'reference_no' => 'UPI9']]]); // Rs 103, Rs 50 paid, Rs 53 udhar
        $this->assertSame(5300, $ramesh->refresh()->balance_paise);

        // A cashier may not cancel.
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'Wrong customer'], ['Idempotency-Key' => 'c-0'])->assertForbidden();

        $this->actingAs($this->owner);
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", [], ['Idempotency-Key' => 'c-1'])->assertUnprocessable(); // a reason is required
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'Wrong customer'], ['Idempotency-Key' => 'c-2'])->assertOk()->assertJsonPath('status', 'cancelled');

        $this->assertSame(20, $this->sellable($soap));
        $this->assertSame(100, $this->sellable($crocin));
        $this->assertSame(2, InventoryMovement::where('type', 'sale_cancel')->count());
        $refund = Payment::where('direction', 'out')->firstOrFail();
        $this->assertSame(['upi', 5000, 'UPI9'], [$refund->method, $refund->amount_paise, $refund->reference_no]);
        $this->assertSame(0, $ramesh->refresh()->balance_paise);
        $entries = $this->getJson("/api/v1/customers/{$ramesh->id}")->json('entries');
        $this->assertSame('reversal', $entries[0]['type']);
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
        $this->assertSame([], app(StockLedger::class)->findMismatches());

        $bill = $this->getJson("/api/v1/sales/{$sale->id}")->assertOk();
        $bill->assertJsonPath('status', 'cancelled')->assertJsonPath('invoice_no', $sale->invoice_no)->assertJsonPath('cancelled.reason', 'Wrong customer')
            ->assertJsonPath('cancelled.by', $this->owner->name)->assertJsonPath('can_cancel', false)->assertJsonPath('can_return', false)
            ->assertJsonCount(1, 'payments')->assertJsonPath('refunds.0.amount_paise', 5000); // the bill shows what was taken; the refund is its own line

        // Cancelled once only; nothing can be returned against it; the number is not reused.
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'again'], ['Idempotency-Key' => 'c-3'])->assertStatus(409)->assertJsonPath('code', 'bill_cancelled');
        $this->actingAs($this->cashier);
        $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertStatus(409)->assertJsonPath('code', 'bill_cancelled');
        $next = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $this->assertNotSame($sale->invoice_no, $next->invoice_no);
        $this->getJson('/api/v1/sales')->assertJsonPath('data.1.status', 'cancelled');
    }

    public function test_cancelling_is_refused_on_a_later_day_or_after_a_return(): void
    {
        $soap = $this->general();
        $this->actingAs($this->owner);
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $this->return($sale, [['line_no' => 1, 'qty' => 1, 'bucket' => 'sellable']])->assertCreated();
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'x'], ['Idempotency-Key' => 'c-1'])->assertStatus(409)->assertJsonPath('code', 'has_returns');

        $yesterday = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        Carbon::setTestNow(Carbon::parse('2026-11-20 09:00', 'Asia/Kolkata'));
        $this->postJson("/api/v1/sales/{$yesterday->id}/cancel", ['reason' => 'x'], ['Idempotency-Key' => 'c-2'])->assertStatus(409)->assertJsonPath('code', 'cancel_same_day_only');
        $this->getJson("/api/v1/sales/{$yesterday->id}")->assertJsonPath('can_cancel', false)->assertJsonPath('can_return', true);
        $this->assertSame(18, $this->sellable($soap)); // 20 - 2 - 1 + 1 returned
    }

    public function test_cancelled_bills_leave_the_sales_reports_and_returns_have_their_own(): void
    {
        $soap = $this->general();
        $this->actingAs($this->owner);
        $kept = $this->sell([['product_id' => $soap->id, 'qty' => 2]], $this->cash(10000));
        $wrong = $this->sell([['product_id' => $soap->id, 'qty' => 4]], $this->cash(20000));
        $this->postJson("/api/v1/sales/{$wrong->id}/cancel", ['reason' => 'x'], ['Idempotency-Key' => 'c-1'])->assertOk();
        $this->return($kept, [['line_no' => 1, 'qty' => 1]])->assertCreated();

        $this->getJson('/api/v1/reports/sales_by_day?from=2026-11-19&to=2026-11-19')->assertOk()->assertJsonPath('totals.total', 10000)->assertJsonPath('totals.bills', 1);
        $this->getJson('/api/v1/reports/bills?from=2026-11-19&to=2026-11-19')->assertOk()->assertJsonCount(1, 'rows');
        $returns = $this->getJson('/api/v1/reports/returns?from=2026-11-19&to=2026-11-19')->assertOk();
        $returns->assertJsonCount(1, 'rows')->assertJsonPath('rows.0.total', 5000)->assertJsonPath('rows.0.refund', 'refund')->assertJsonPath('rows.0.bill_no', $kept->invoice_no);
        $this->getJson('/api/v1/reports/returns/export?from=2026-11-19&to=2026-11-19&format=csv')->assertOk();
    }

    public function test_another_shops_bill_cannot_be_returned_or_cancelled(): void
    {
        $soap = $this->general();
        $sale = $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));
        $stranger = User::factory()->create(['store_id' => Store::create(['name' => 'Other'])->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger->refresh());
        $this->return($sale, [['line_no' => 1, 'qty' => 1]])->assertNotFound();
        $this->postJson("/api/v1/sales/{$sale->id}/cancel", ['reason' => 'x'], ['Idempotency-Key' => 'c-9'])->assertNotFound();
        $this->getJson('/api/v1/returns/1')->assertNotFound();
    }
}
