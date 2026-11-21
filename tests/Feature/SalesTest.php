<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Enums\GstMode;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\DrugSchedule;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Scenarios 1–5, 7 and 14 of docs/BUSINESS_WORKFLOWS.md. */
class SalesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
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

    /** A strip-of-10 medicine at Rs 30 a strip with the given batches [batch no, expiry month, strips]. */
    private function medicine(string $name, string $schedule = 'NONE', array $batches = [['B1', '2028-05', 10]], bool $barcode = false): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('TAB'), 'pack_unit_id' => $this->unit('STRIP'), 'pack_size' => 10,
            'allow_loose_sale' => true, 'mrp_paise' => 3000, 'selling_price_paise' => 3000,
            'barcodes' => $barcode ? [['barcode' => '8900000000011']] : [],
            'medicine' => ['generic_name' => 'Paracetamol', 'drug_schedule_id' => DrugSchedule::where('code', $schedule)->value('id')],
        ]);
        foreach ($batches as [$batchNo, $expiry, $strips]) {
            $this->stock($product, ['qty' => $strips, 'cost_paise' => 2340, 'batch_no' => $batchNo,
                'expiry_date' => Carbon::createFromFormat('!Y-m', $expiry)->endOfMonth()->toDateString()]);
        }

        return $product;
    }

    private function general(string $name, string $taxRate, int $pricePaise, array $extra = [], int $stock = 20): Product
    {
        $product = app(SaveProduct::class)->handle($this->owner, $extra + [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax($taxRate),
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => $pricePaise, 'selling_price_paise' => $pricePaise,
        ]);
        if ($stock > 0) {
            $this->stock($product, ['qty' => $stock, 'cost_paise' => (int) ($pricePaise * 0.9)]);
        }

        return $product;
    }

    private function stock(Product $product, array $item): void
    {
        app(AddOpeningStock::class)->handle($this->owner, $product, [$item]);
    }

    private function sell(array $lines, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', $extra + ['ulid' => (string) Str::ulid(), 'lines' => $lines, 'payments' => []]);
    }

    private function cash(int $paise): array
    {
        return ['payments' => [['method' => 'cash', 'amount_paise' => $paise]]];
    }

    private function customer(array $attributes = []): Customer
    {
        return Customer::create($attributes + [
            'ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'name' => 'Ramesh Patil',
            'mobile' => '9800000010', 'search_text' => 'ramesh patil 9800000010',
        ])->refresh();
    }

    /** Scenario 1 */
    public function test_cash_sale_reduces_stock_records_payment_and_numbers_the_bill(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');

        $bill = $this->sell([['product_id' => $crocin->id, 'qty' => 2]], $this->cash(6000))
            ->assertCreated()
            ->assertJsonPath('invoice_no', '26-27/000001')
            ->assertJsonPath('doc_kind', 'tax_invoice')
            ->assertJsonPath('totals.total_paise', 6000)
            ->assertJsonPath('totals.taxable_paise', 5714)
            ->assertJsonPath('totals.cgst_paise', 143)
            ->assertJsonPath('totals.sgst_paise', 143)
            ->assertJsonPath('totals.credit_paise', 0)
            ->assertJsonPath('items.0.batch_no', 'B1')
            ->assertJsonPath('items.0.qty', 2)
            ->assertJsonPath('tax_summary.0.rate_percent', '5.00')
            ->assertJsonPath('shop.gstin', '27AAAAA0000A1Z5')
            ->assertJsonPath('cashier', $this->cashier->name)
            ->assertJsonMissingPath('items.0.cost_paise');

        $this->assertSame(80, StockBatch::sole()->qty_sellable);
        $sale = Sale::sole();
        $this->assertSame(4680, $sale->cost_paise); // 20 tablets at Rs 2.34
        $payment = Payment::sole();
        $this->assertSame(['in', 'cash', 6000, $sale->id], [$payment->direction, $payment->method, $payment->amount_paise, $payment->payable_id]);
        $this->assertSame([], app(StockLedger::class)->findMismatches());

        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertJsonPath('invoice_no', '26-27/000002');
        $this->getJson("/api/v1/sales/{$bill->json('id')}")->assertOk()->assertJsonPath('invoice_no', '26-27/000001');
        $this->getJson('/api/v1/sales?search=crocin')->assertJsonPath('meta.total', 2)->assertJsonPath('data.0.methods', ['cash']);
    }

    /** Scenarios 2 and 3 */
    public function test_sale_screen_finds_products_by_barcode_and_by_name(): void
    {
        $this->medicine('Crocin 500 Tablet', batches: [['NEAR', '2027-01', 3], ['FAR', '2028-05', 10]], barcode: true);
        $this->general('Sugar loose', 'GST 5%', 4400, stock: 0);

        $this->getJson('/api/v1/pos/search?q=8900000000011')
            ->assertOk()->assertJsonPath('matched_barcode', true)->assertJsonPath('barcode_unit', 'pack')
            ->assertJsonPath('data.0.name', 'Crocin 500 Tablet')
            ->assertJsonPath('data.0.batches.0.batch_no', 'NEAR') // earliest expiry first
            ->assertJsonPath('data.0.stock_qty', 130)
            ->assertJsonPath('data.0.price_paise', 3000);

        $this->getJson('/api/v1/pos/search?q=paracet')->assertJsonPath('data.0.name', 'Crocin 500 Tablet'); // by salt
        $this->getJson('/api/v1/pos/search?q=sugar')->assertJsonPath('data.0.stock_qty', 0)->assertJsonCount(0, 'data.0.batches');
        $this->getJson('/api/v1/pos/search?q=')->assertJsonCount(0, 'data');
    }

    /** Scenario 4 (batch side): first expiry first, never an expired batch. */
    public function test_stock_is_taken_from_the_batch_expiring_first_and_expired_batches_are_never_sold(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet', batches: [['FAR', '2028-05', 10], ['NEAR', '2026-12', 2], ['OLD', '2026-11', 5]]);

        // 3 strips: 2 from NEAR... but OLD (Nov 2026) expires first and is still good today.
        $this->sell([['product_id' => $crocin->id, 'qty' => 6]], $this->cash(18000))
            ->assertCreated()
            ->assertJsonPath('items.0.batch_no', 'OLD')->assertJsonPath('items.0.qty', 5)
            ->assertJsonPath('items.1.batch_no', 'NEAR')->assertJsonPath('items.1.qty', 1);

        Carbon::setTestNow(Carbon::parse('2027-01-02 10:00', 'Asia/Kolkata')); // NEAR has now expired with 1 strip left

        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertCreated()->assertJsonPath('items.0.batch_no', 'FAR');

        $near = StockBatch::where('batch_no', 'NEAR')->sole();
        $this->sell([['product_id' => $crocin->id, 'qty' => 1, 'batch_id' => $near->id]], $this->cash(3000))
            ->assertStatus(409)->assertJsonPath('code', 'batch_expired');
        $this->assertSame(10, $near->refresh()->qty_sellable);

        // Only 9 good strips remain; the expired one does not count.
        $this->sell([['product_id' => $crocin->id, 'qty' => 10]], $this->cash(30000))
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('line_errors.0.available', 9);
        $this->assertSame(2, Sale::count());
    }

    public function test_loose_tablets_and_whole_strips_are_priced_exactly_and_a_strip_is_not_split_across_batches(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet', batches: [['A', '2027-06', 1], ['B', '2028-05', 5]]);

        // 3 loose tablets from a Rs 30 strip of 10 = Rs 9.00
        $this->sell([['product_id' => $crocin->id, 'unit' => 'base', 'qty' => 3]], $this->cash(900))
            ->assertCreated()->assertJsonPath('items.0.unit_code', 'TAB')->assertJsonPath('totals.total_paise', 900);

        // Batch A now holds 7 loose tablets: a whole strip must come from batch B.
        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertCreated()->assertJsonPath('items.0.batch_no', 'B');
        $this->assertSame(7, StockBatch::where('batch_no', 'A')->sole()->qty_sellable);

        $noLoose = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => 'Pen box', 'tax_rate_id' => $this->tax('GST 18%'), 'base_unit_id' => $this->unit('PCS'),
            'pack_unit_id' => $this->unit('BOX'), 'pack_size' => 20, 'allow_loose_sale' => false, 'selling_price_paise' => 20000,
        ]);
        $this->stock($noLoose, ['qty' => 2, 'cost_paise' => 15000]);
        $this->sell([['product_id' => $noLoose->id, 'unit' => 'base', 'qty' => 1]], $this->cash(1000))
            ->assertStatus(409)->assertJsonPath('code', 'loose_not_allowed');
    }

    /** Scenario 5 */
    public function test_mixed_bill_taxes_each_line_at_its_own_rate_and_rounds_to_the_rupee(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');                       // 5%
        $soap = $this->general('Vim Bar', 'GST 18%', 2050);                   // 18%
        $notebook = $this->general('Notebook', 'GST 0% (Nil)', 6000);         // nil

        $lines = [
            ['product_id' => $crocin->id, 'qty' => 1],
            ['product_id' => $soap->id, 'qty' => 1],
            ['product_id' => $notebook->id, 'qty' => 1, 'discount_percent' => 10],
        ];
        $this->cashier->givePermissionTo('sales.discount_override'); // a discount needs this permission

        $preview = $this->postJson('/api/v1/sales/preview', ['lines' => $lines])->assertOk()
            ->assertJsonPath('doc_kind', 'invoice_cum_bill_of_supply')
            ->assertJsonPath('totals.gross_paise', 11050)
            ->assertJsonPath('totals.discount_paise', 600)
            ->assertJsonPath('totals.round_off_paise', 50) // Rs 104.50 becomes Rs 105
            ->assertJsonMissingPath('totals.cost_paise')
            ->assertJsonMissingPath('lines.0.cost_paise')
            ->json();
        $this->assertSame(0, Sale::count()); // preview saves nothing

        $bill = $this->sell($lines, $this->cash($preview['totals']['total_paise']))->assertCreated()->json();

        $this->assertSame($preview['totals']['total_paise'], $bill['totals']['total_paise']);
        $this->assertSame(['5.00', '18.00'], array_column($bill['tax_summary'], 'rate_percent'));
        $this->assertSame(2857, $bill['tax_summary'][0]['taxable_paise']);  // 3000 / 1.05
        $this->assertSame(1737, $bill['tax_summary'][1]['taxable_paise']);  // 2050 / 1.18
        $this->assertSame(5400, $bill['items'][2]['line_total_paise']);     // nil-rated, 10% off
        $totals = $bill['totals'];
        $this->assertSame($totals['total_paise'], $totals['taxable_paise'] + $totals['cgst_paise'] + $totals['sgst_paise'] + $totals['round_off_paise']);
        $this->assertSame(0, $totals['total_paise'] % 100);
    }

    public function test_bill_title_and_tax_follow_the_shops_gst_status(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');
        $notebook = $this->general('Notebook', 'GST 0% (Nil)', 6000);
        $line = fn (Product $p) => [['product_id' => $p->id, 'qty' => 1]];

        $this->sell($line($notebook), $this->cash(6000))->assertJsonPath('doc_kind', 'bill_of_supply');

        $this->owner->store->update(['gst_mode' => GstMode::Composition]);
        $this->cashier->unsetRelation('store'); // the test reuses one signed-in user object across requests
        $this->sell($line($crocin), $this->cash(3000))
            ->assertJsonPath('doc_kind', 'bill_of_supply')->assertJsonPath('totals.cgst_paise', 0)->assertJsonPath('totals.taxable_paise', 3000);

        $this->owner->store->update(['gst_mode' => GstMode::Unregistered, 'gstin' => null]);
        $this->cashier->unsetRelation('store');
        $this->sell($line($crocin), $this->cash(3000))
            ->assertJsonPath('doc_kind', 'cash_memo')->assertJsonPath('totals.sgst_paise', 0)->assertJsonCount(0, 'tax_summary');
    }

    /** Scenario 7 */
    public function test_udhar_sale_with_part_payment_and_later_repayment_keeps_the_ledger_in_step(): void
    {
        $soap = $this->general('Dettol Soap', 'GST 5%', 5000);
        $ramesh = $this->customer();

        // Rs 500 of goods, Rs 200 paid now.
        $this->sell([['product_id' => $soap->id, 'qty' => 10]], ['customer_id' => $ramesh->id] + $this->cash(20000))
            ->assertCreated()
            ->assertJsonPath('totals.credit_paise', 30000)
            ->assertJsonPath('customer.balance_paise', 30000);

        // Rs 100 received later.
        $this->postJson("/api/v1/customers/{$ramesh->id}/payments", ['amount_paise' => 10000, 'method' => 'upi', 'reference_no' => 'UPI123'], ['Idempotency-Key' => 'pay-1'])
            ->assertCreated()->assertJsonPath('balance_paise', 20000);
        // The same request again (double click) does not take the money twice.
        $this->postJson("/api/v1/customers/{$ramesh->id}/payments", ['amount_paise' => 10000, 'method' => 'upi'], ['Idempotency-Key' => 'pay-1'])
            ->assertCreated()->assertJsonPath('balance_paise', 20000);

        $statement = $this->getJson("/api/v1/customers/{$ramesh->id}")->assertOk()->json('entries');
        $this->assertSame([[0, 10000, 20000], [0, 20000, 30000], [50000, 0, 50000]],
            array_map(fn ($e) => [$e['debit_paise'], $e['credit_paise'], $e['balance_after_paise']], $statement));
        $this->assertSame(['payment', 'payment', 'sale'], array_column($statement, 'type'));
        $this->assertNotNull($statement[2]['sale_id']);

        $this->assertSame(20000, $ramesh->refresh()->balance_paise);
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
        $this->assertSame(30000, (int) Payment::sum('amount_paise'));
        $this->getJson('/api/v1/customers?owing=1')->assertJsonPath('total_owed_paise', 20000)->assertJsonPath('customers_owing', 1);

        // An advance: paying more than is owed leaves the shop owing the customer.
        $this->postJson("/api/v1/customers/{$ramesh->id}/payments", ['amount_paise' => 25000, 'method' => 'cash'], ['Idempotency-Key' => 'pay-2'])
            ->assertJsonPath('balance_paise', -5000);
    }

    public function test_udhar_needs_a_customer_respects_the_limit_and_overpayment_is_refused(): void
    {
        $soap = $this->general('Dettol Soap', 'GST 5%', 5000);
        $line = [['product_id' => $soap->id, 'qty' => 2]]; // Rs 100

        $this->sell($line)->assertStatus(409)->assertJsonPath('code', 'customer_required'); // walk-in cannot take udhar
        $this->sell($line, $this->cash(10100))->assertStatus(409)->assertJsonPath('code', 'overpaid');

        $limited = $this->customer(['credit_limit_paise' => 15000]);
        $this->sell($line, ['customer_id' => $limited->id])->assertCreated();          // Rs 100 owed
        $this->sell($line, ['customer_id' => $limited->id])->assertStatus(409)->assertJsonPath('code', 'credit_limit_exceeded');
        // A cashier cannot wave the limit through.
        $this->sell($line, ['customer_id' => $limited->id, 'override_credit_limit' => true])->assertStatus(409);
        $this->actingAs($this->owner);
        $this->sell($line, ['customer_id' => $limited->id, 'override_credit_limit' => true])->assertCreated();

        $this->assertSame(20000, $limited->refresh()->balance_paise);
        $this->assertSame(2, Sale::count());
        $this->assertSame(16, (int) StockBatch::sum('qty_sellable')); // refused bills took no stock
    }

    /** Scenario 14 */
    public function test_the_same_cart_sent_twice_makes_one_bill(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');
        $cart = ['ulid' => (string) Str::ulid(), 'lines' => [['product_id' => $crocin->id, 'qty' => 2]]] + $this->cash(6000);

        $first = $this->postJson('/api/v1/sales', $cart)->assertCreated()->assertHeader('Idempotency-Replayed', 'false');
        $second = $this->postJson('/api/v1/sales', $cart)->assertOk()->assertHeader('Idempotency-Replayed', 'true');

        $this->assertSame($first->json('invoice_no'), $second->json('invoice_no'));
        $this->assertSame(1, Sale::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(80, StockBatch::sole()->qty_sellable);
    }

    public function test_a_refused_bill_uses_no_bill_number_and_leaves_nothing_behind(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');

        $this->sell([['product_id' => $crocin->id, 'qty' => 99]], $this->cash(1000))->assertStatus(409);
        $this->sell([['product_id' => 99999, 'qty' => 1]], $this->cash(1000))->assertStatus(409)->assertJsonPath('code', 'product_unavailable');
        $this->sell([], $this->cash(1000))->assertStatus(422);

        $this->assertSame(0, Sale::count() + Payment::count());
        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertJsonPath('invoice_no', '26-27/000001');
    }

    /** Scenario 4 (control side) */
    public function test_prescription_medicine_needs_doctor_and_patient_details_and_a_pharmacist(): void
    {
        $mox = $this->medicine('Mox 500 Capsule', 'H');
        $line = [['product_id' => $mox->id, 'qty' => 1]];
        $rx = ['prescription' => ['prescriber_name' => 'Dr. Deshmukh', 'prescriber_address' => 'Wadgaon', 'patient_name' => 'Sunita Jadhav']];

        $this->postJson('/api/v1/sales/preview', ['lines' => $line])
            ->assertJsonPath('requires.prescription', true)->assertJsonPath('requires.pharmacist', true);

        $this->sell($line, $this->cash(3000))->assertStatus(409)->assertJsonPath('code', 'prescription_required');
        $this->sell($line, $this->cash(3000) + $rx)->assertStatus(409)->assertJsonPath('code', 'pharmacist_required');

        $pharmacist = $this->userWithRole(RoleName::Pharmacist, ['is_pharmacist' => true, 'pharmacist_reg_no' => 'MSPC-1', 'pin' => '4321']);
        $this->getJson('/api/v1/pos/pharmacists')->assertJsonPath('data.0.id', $pharmacist->id)->assertJsonMissingPath('data.0.pin');

        $this->sell($line, $this->cash(3000) + $rx + ['pharmacist' => ['user_id' => $pharmacist->id, 'pin' => '0000']])
            ->assertStatus(409)->assertJsonPath('code', 'pin_wrong')->assertJsonPath('tries_left', 4);
        $this->assertSame(0, Sale::count());

        // The cashier bills; the pharmacist approves with their PIN.
        $this->sell($line, $this->cash(3000) + $rx + ['pharmacist' => ['user_id' => $pharmacist->id, 'pin' => '4321']])
            ->assertCreated()
            ->assertJsonPath('pharmacist.reg_no', 'MSPC-1')
            ->assertJsonPath('prescription.prescriber_name', 'Dr. Deshmukh')
            ->assertJsonPath('prescription.patient_name', 'Sunita Jadhav')
            ->assertJsonPath('items.0.schedule_code', 'H')
            ->assertJsonPath('has_medicines', true);

        // A pharmacist who is signed in needs no PIN, but still needs the prescription details.
        $this->actingAs($pharmacist);
        $this->sell($line, $this->cash(3000))->assertStatus(409)->assertJsonPath('code', 'prescription_required');
        $this->sell($line, $this->cash(3000) + $rx)->assertCreated();
        $this->assertSame($pharmacist->id, Sale::latest('id')->first()->verified_by_pharmacist_id);

        // Ordinary medicine needs neither.
        $crocin = $this->medicine('Crocin 500 Tablet');
        $this->actingAs($this->cashier);
        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertCreated()->assertJsonPath('prescription', null);
    }

    public function test_split_payment_and_customers_are_recorded(): void
    {
        $soap = $this->general('Dettol Soap', 'GST 5%', 5000);
        $ramesh = $this->customer();

        $this->sell([['product_id' => $soap->id, 'qty' => 4]], ['customer_id' => $ramesh->id, 'payments' => [
            ['method' => 'cash', 'amount_paise' => 5000],
            ['method' => 'upi', 'amount_paise' => 15000, 'reference_no' => 'UPI999'],
        ]])->assertCreated()->assertJsonPath('totals.paid_paise', 20000)->assertJsonCount(2, 'payments');

        $this->assertSame(0, $ramesh->refresh()->balance_paise);
        $this->assertSame(0, $ramesh->ledger()->count()); // fully paid bills do not touch the udhar account
        $this->getJson("/api/v1/sales?customer_id={$ramesh->id}")->assertJsonPath('data.0.methods', ['cash', 'upi']);
    }

    public function test_customers_can_be_added_at_the_counter_with_a_duplicate_mobile_warning(): void
    {
        $this->postJson('/api/v1/customers', ['name' => 'Sunita Jadhav', 'mobile' => '9700000022', 'village' => 'Wadgaon', 'credit_limit_paise' => 50000])
            ->assertCreated()->assertJsonPath('credit_limit_paise', 0); // a cashier cannot set an udhar limit
        $this->postJson('/api/v1/customers', ['name' => 'S. Jadhav', 'mobile' => '9700000022'])
            ->assertStatus(409)->assertJsonPath('code', 'possible_duplicate')->assertJsonPath('duplicates.0.name', 'Sunita Jadhav');
        $this->postJson('/api/v1/customers', ['name' => 'S. Jadhav', 'mobile' => '9700000022', 'confirm_duplicate' => true])->assertCreated();
        $this->postJson('/api/v1/customers', ['name' => 'Bad', 'mobile' => '12345'])->assertStatus(422);
        $this->postJson('/api/v1/customers', ['name' => 'No Phone'])->assertCreated(); // only a name is needed

        $this->getJson('/api/v1/customers?search=wadgaon')->assertJsonPath('meta.total', 1);
        $this->getJson('/api/v1/customers?search=9700')->assertJsonPath('meta.total', 2);

        $this->actingAs($this->owner);
        $id = Customer::where('name', 'No Phone')->value('id');
        $this->putJson("/api/v1/customers/{$id}", ['name' => 'No Phone', 'credit_limit_paise' => 50000])->assertJsonPath('credit_limit_paise', 50000);
    }

    public function test_permissions_for_billing_and_receiving_money(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet');
        $ramesh = $this->customer();
        $manager = $this->userWithRole(RoleName::StoreManager); // buys and stocks, does not bill

        $this->actingAs($manager);
        $this->sell([['product_id' => $crocin->id, 'qty' => 1]], $this->cash(3000))->assertForbidden();
        $this->getJson('/api/v1/pos/search?q=crocin')->assertForbidden();
        $this->getJson('/api/v1/sales')->assertForbidden();
        $this->postJson("/api/v1/customers/{$ramesh->id}/payments", ['amount_paise' => 100, 'method' => 'cash'], ['Idempotency-Key' => 'p'])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/sales')->assertUnauthorized();
        $this->postJson('/api/v1/sales/preview', ['lines' => []])->assertUnauthorized();
        $this->assertSame(0, Sale::count());
    }
}
