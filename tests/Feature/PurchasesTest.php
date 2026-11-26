<?php

namespace Tests\Feature;

use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\SupplierLedger;
use App\Enums\GstMode;
use App\Enums\RoleName;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\SupplierLedgerEntry;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Scenarios S6 (purchase), S8 (supplier payment) and S11 (purchase return) of docs/BUSINESS_WORKFLOWS.md. */
class PurchasesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->manager = $this->userWithRole(RoleName::StoreManager);
        $this->owner->store->update(['gst_mode' => GstMode::Regular, 'gstin' => '27AAAAA0000A1Z5', 'state_code' => '27']);
        $this->actingAs($this->manager);
    }

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function tax(string $name): int
    {
        return TaxRate::where('name', $name)->value('id');
    }

    /** A strip-of-10 medicine, MRP Rs 30 a strip, batch and expiry tracked, no stock yet. */
    private function medicine(string $name = 'Crocin 500'): Product
    {
        return app(SaveProduct::class)->handle($this->owner, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('TAB'), 'pack_unit_id' => $this->unit('STRIP'), 'pack_size' => 10,
            'allow_loose_sale' => true, 'mrp_paise' => 3000, 'selling_price_paise' => 3000,
            'medicine' => ['generic_name' => 'Paracetamol'],
        ]);
    }

    private function general(string $name = 'Sugar 1kg', string $taxRate = 'GST 5%'): Product
    {
        return app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax($taxRate),
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => 5000, 'selling_price_paise' => 4800,
        ]);
    }

    private function supplier(array $extra = []): Supplier
    {
        $response = $this->postJson('/api/v1/suppliers', $extra + ['name' => 'Mahalaxmi Pharma Distributors', 'mobile' => '9822000001', 'gstin' => '27ABCDE1234F1Z5', 'payment_terms_days' => 15]);
        $response->assertCreated();

        return Supplier::findOrFail($response->json('id'));
    }

    /** @param list<array<string, mixed>> $lines */
    private function draft(Supplier $supplier, array $lines, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/purchases', $extra + ['supplier_id' => $supplier->id, 'supplier_invoice_no' => 'INV-778', 'invoice_date' => '2026-11-26', 'lines' => $lines]);
    }

    private function medicineLine(Product $product, array $extra = []): array
    {
        return $extra + ['product_id' => $product->id, 'unit' => 'pack', 'qty' => 10, 'free_qty' => 1, 'rate_paise' => 2000, 'discount_percent' => 10, 'batch_no' => 'B501', 'expiry_month' => '2028-05', 'mrp_paise' => 3200];
    }

    /** A repeat-safe request with its own key, set for this request only (withHeader would stick to the ones after). */
    private function keyedPost(string $key, string $uri, array $body = []): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => $key]);
    }

    private function confirm(int $purchaseId, ?array $payment = null): TestResponse
    {
        return $this->keyedPost((string) Str::uuid(), "/api/v1/purchases/{$purchaseId}/confirm", $payment ? ['payment' => $payment] : []);
    }

    // ---- suppliers ------------------------------------------------------------------------------------------

    public function test_supplier_is_created_with_opening_balance_and_contacts(): void
    {
        $supplier = $this->supplier(['opening_balance_paise' => 150000]);
        $this->assertSame(150000, $supplier->balance_paise);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_id' => $supplier->id, 'type' => 'opening', 'credit_paise' => 150000, 'balance_after_paise' => 150000]);

        $this->postJson("/api/v1/suppliers/{$supplier->id}/contacts", ['name' => 'Rahul (MR)', 'role' => 'mr', 'company' => 'Cipla', 'mobile' => '9822000009', 'visit_day' => 2, 'schemes' => '10+1 on Crocin'])
            ->assertCreated()->assertJsonPath('visit_day', 2);
        $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertOk()
            ->assertJsonPath('contacts.0.company', 'Cipla')
            ->assertJsonPath('entries.0.type', 'opening');
        $this->getJson('/api/v1/suppliers/visits')->assertOk()->assertJsonPath('data.0.supplier.name', 'Mahalaxmi Pharma Distributors');
    }

    public function test_same_supplier_name_warns_once_then_saves(): void
    {
        $this->supplier();
        $this->postJson('/api/v1/suppliers', ['name' => 'mahalaxmi pharma distributors'])->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->postJson('/api/v1/suppliers', ['name' => 'mahalaxmi pharma distributors', 'confirm_duplicate' => true])->assertCreated();
        $this->postJson('/api/v1/suppliers', ['name' => 'X', 'gstin' => 'bad'])->assertStatus(422);
    }

    public function test_cashier_cannot_see_or_change_suppliers_or_purchases(): void
    {
        $supplier = $this->supplier();
        $this->actingAs($this->userWithRole(RoleName::Cashier));
        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->postJson('/api/v1/suppliers', ['name' => 'Y'])->assertForbidden();
        $this->getJson('/api/v1/purchases')->assertForbidden();
        $this->postJson('/api/v1/purchases', ['supplier_id' => $supplier->id, 'lines' => []])->assertForbidden();
        $this->postJson('/api/v1/purchase-returns', [])->assertForbidden();
    }

    // ---- pricing --------------------------------------------------------------------------------------------

    public function test_preview_prices_a_line_like_the_paper_invoice_and_shares_cost_with_free_packs(): void
    {
        $product = $this->medicine();
        $supplier = $this->supplier();
        $preview = $this->postJson('/api/v1/purchases/preview', ['supplier_id' => $supplier->id, 'other_charges_paise' => 1250, 'lines' => [$this->medicineLine($product)]])->assertOk();

        // 10 strips × Rs 20 = Rs 200, less 10% = Rs 180 taxable, GST 5% = Rs 9 → Rs 189; +Rs 12.50 charges = 201.50 → rounds up to 202.
        $preview->assertJsonPath('lines.0.gross_paise', 20000)
            ->assertJsonPath('lines.0.discount_paise', 2000)
            ->assertJsonPath('lines.0.taxable_paise', 18000)
            ->assertJsonPath('lines.0.cgst_paise', 450)
            ->assertJsonPath('lines.0.sgst_paise', 450)
            ->assertJsonPath('lines.0.line_total_paise', 18900)
            ->assertJsonPath('lines.0.qty_base', 110)
            ->assertJsonPath('totals.total_paise', 20200)
            ->assertJsonPath('totals.round_off_paise', 50)
            ->assertJsonPath('inter_state', false);
        // Regular shop: GST is input credit, so cost = Rs 180 over 110 tablets = 163.636364 paise a tablet, Rs 16.36 a strip.
        $this->assertSame('163.636364', $preview->json('lines.0.cost_rate'));
        $this->assertSame(1636, $preview->json('lines.0.cost_per_pack_paise'));
    }

    public function test_an_out_of_state_supplier_is_charged_igst(): void
    {
        $product = $this->general();
        $supplier = $this->supplier(['gstin' => '24ABCDE1234F1Z5']);
        $this->postJson('/api/v1/purchases/preview', ['supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]]])
            ->assertOk()->assertJsonPath('inter_state', true)->assertJsonPath('lines.0.igst_paise', 2000)->assertJsonPath('lines.0.cgst_paise', 0);
    }

    public function test_a_composition_shop_carries_the_gst_into_its_cost(): void
    {
        $this->owner->store->update(['gst_mode' => GstMode::Composition]);
        $this->manager->unsetRelation('store');
        $product = $this->general();
        $supplier = $this->supplier();
        $preview = $this->postJson('/api/v1/purchases/preview', ['supplier_id' => $supplier->id, 'lines' => [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]]])->assertOk();
        // Rs 400 + 5% = Rs 420 for 10 pieces: Rs 42 each.
        $this->assertSame('4200.000000', $preview->json('lines.0.cost_rate'));
    }

    public function test_lines_are_refused_for_missing_batch_expired_stock_and_price_above_mrp(): void
    {
        $product = $this->medicine();
        $supplier = $this->supplier();
        $errors = fn (array $line) => $this->postJson('/api/v1/purchases/preview', ['supplier_id' => $supplier->id, 'lines' => [$this->medicineLine($product, $line)]])->json('errors.0.code');

        $this->assertSame('batch_required', $errors(['batch_no' => '']));
        $this->assertSame('expiry_required', $errors(['expiry_month' => null]));
        $this->assertSame('already_expired', $errors(['expiry_month' => '2026-10']));
        $this->assertSame('price_above_mrp', $errors(['mrp_paise' => 3000, 'selling_price_paise' => 3100]));
        $this->assertSame('qty_required', $errors(['qty' => 0, 'free_qty' => 0]));
        $this->assertNull($errors(['qty' => 0, 'free_qty' => 2])); // a free sample is a valid line
        $this->draft($supplier, [$this->medicineLine($product, ['batch_no' => ''])])->assertStatus(409)->assertJsonPath('code', 'batch_required');
    }

    // ---- drafts ---------------------------------------------------------------------------------------------

    public function test_a_draft_touches_no_stock_can_be_edited_and_deleted_and_warns_on_a_repeated_invoice_number(): void
    {
        $product = $this->medicine();
        $supplier = $this->supplier();
        $id = $this->draft($supplier, [$this->medicineLine($product)])->assertCreated()->assertJsonPath('status', 'draft')->assertJsonPath('due_date', '2026-12-12')->json('id');
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, SupplierLedgerEntry::count());

        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $supplier->id, 'supplier_invoice_no' => 'INV-778', 'lines' => [$this->medicineLine($product, ['qty' => 5])]])
            ->assertOk()->assertJsonPath('items.0.qty', 5)->assertJsonCount(1, 'items');

        $this->draft($supplier, [$this->medicineLine($product)])->assertStatus(409)->assertJsonPath('code', 'duplicate_supplier_invoice')->assertJsonPath('purchase.id', $id);
        $this->draft($supplier, [$this->medicineLine($product)], ['confirm_duplicate' => true])->assertCreated();

        $this->deleteJson("/api/v1/purchases/{$id}")->assertNoContent();
        $this->assertDatabaseMissing('purchases', ['id' => $id]);
        $this->assertDatabaseMissing('purchase_items', ['purchase_id' => $id]);
    }

    // ---- confirm --------------------------------------------------------------------------------------------

    public function test_confirming_creates_stock_ledger_entry_payment_and_price_history_in_one_go(): void
    {
        $medicine = $this->medicine();
        $sugar = $this->general();
        $supplier = $this->supplier();
        $id = $this->draft($supplier, [
            $this->medicineLine($medicine),
            ['product_id' => $sugar->id, 'qty' => 20, 'rate_paise' => 4000, 'mrp_paise' => 5000],
        ])->json('id');

        $confirmed = $this->confirm($id, ['amount_paise' => 50000, 'method' => 'upi', 'reference_no' => 'UPI123'])->assertOk();
        $confirmed->assertJsonPath('status', 'confirmed')->assertJsonPath('paid_paise', 50000);
        $this->assertSame('P26-27/000001', $confirmed->json('purchase_no'));
        $total = $confirmed->json('total_paise');
        $this->assertSame(18900 + 84000, $total); // 189 + (800 + 5%)

        // Stock: one batch per line, quantities incl. free packs, cost from the line, supplier recorded.
        $batch = StockBatch::where('product_id', $medicine->id)->firstOrFail();
        $this->assertSame(110, $batch->qty_sellable);
        $this->assertSame('B501', $batch->batch_no);
        $this->assertSame('2028-05-31', $batch->expiry_date->toDateString());
        $this->assertSame(3200, $batch->mrp_paise);
        $this->assertSame($supplier->id, $batch->supplier_id);
        $this->assertSame('163.636364', (string) $batch->cost_rate);
        $this->assertSame(2, InventoryMovement::where('type', 'purchase')->where('source_id', $id)->count());
        $this->assertSame([], app(StockLedger::class)->findMismatches());

        // Supplier owes: total, less what was paid now.
        $supplier->refresh();
        $this->assertSame($total - 50000, $supplier->balance_paise);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_id' => $supplier->id, 'type' => 'purchase', 'credit_paise' => $total]);
        $this->assertDatabaseHas('supplier_ledger_entries', ['supplier_id' => $supplier->id, 'type' => 'payment', 'debit_paise' => 50000]);
        $this->assertDatabaseHas('payments', ['direction' => 'out', 'method' => 'upi', 'amount_paise' => 50000, 'party_id' => $supplier->id, 'payable_id' => $id]);

        // The pack now says MRP 32: the product follows it; the cost is in the history.
        $this->assertSame(3200, $medicine->fresh()->mrp_paise);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'mrp', 'old_paise' => 3000, 'new_paise' => 3200, 'source' => 'purchase']);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'cost', 'new_paise' => 1636, 'source' => 'purchase']);

        // Confirmed documents are frozen.
        $this->confirm($id)->assertStatus(409)->assertJsonPath('code', 'purchase_not_draft');
        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $supplier->id, 'lines' => []])->assertStatus(409);
        $this->deleteJson("/api/v1/purchases/{$id}")->assertStatus(409);
        $this->getJson('/api/v1/purchases?status=due')->assertOk()->assertJsonCount(1, 'data');
    }

    public function test_confirm_refuses_overpayment_and_an_empty_draft(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 1, 'rate_paise' => 4000]])->json('id');
        $this->confirm($id, ['amount_paise' => 999999, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'overpaid');
        $this->assertSame('draft', Purchase::find($id)->status);
        $this->assertSame(0, StockBatch::count());
    }

    public function test_confirm_is_repeat_safe_with_the_same_key(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 2, 'rate_paise' => 4000]])->json('id');
        $key = (string) Str::uuid();
        $first = $this->keyedPost($key, "/api/v1/purchases/{$id}/confirm", ['payment' => ['amount_paise' => 8400, 'method' => 'cash']])->assertOk();
        $second = $this->keyedPost($key, "/api/v1/purchases/{$id}/confirm", ['payment' => ['amount_paise' => 8400, 'method' => 'cash']])->assertOk();
        $this->assertSame($first->json('purchase_no'), $second->json('purchase_no'));
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, StockBatch::count());
    }

    // ---- supplier payments (S8) -------------------------------------------------------------------------------

    public function test_paying_a_supplier_reduces_the_balance_and_what_is_left_on_the_purchase(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 100, 'rate_paise' => 4000]])->json('id'); // Rs 4,200
        $this->confirm($id, ['amount_paise' => 100000, 'method' => 'cash'])->assertOk();

        $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => 200000, 'method' => 'upi', 'purchase_id' => $id])
            ->assertCreated()->assertJsonPath('balance_paise', 420000 - 300000);
        $this->assertSame(300000, Purchase::find($id)->paid_paise);

        $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => 200000, 'method' => 'upi', 'purchase_id' => $id])
            ->assertStatus(409)->assertJsonPath('code', 'overpaid');

        // On account, no purchase named.
        $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => 20000, 'method' => 'cash'])
            ->assertCreated()->assertJsonPath('balance_paise', 100000);
        $this->postJson("/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => 1, 'method' => 'cash'], ['Idempotency-Key' => ''])->assertStatus(400); // key required
        $this->assertSame([], app(SupplierLedger::class)->findMismatches());
    }

    // ---- cancel (F7) ----------------------------------------------------------------------------------------

    public function test_a_confirmed_purchase_can_be_cancelled_only_while_its_stock_is_untouched(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]])->json('id');
        $this->confirm($id, ['amount_paise' => 10000, 'method' => 'cash'])->assertOk();
        $supplier->refresh();
        $this->assertSame(42000 - 10000, $supplier->balance_paise);

        $this->keyedPost((string) Str::uuid(), "/api/v1/purchases/{$id}/cancel", ['reason' => 'Wrong supplier chosen'])
            ->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelled.reason', 'Wrong supplier chosen');
        $this->assertSame(0, StockBatch::first()->qty_sellable);
        $this->assertSame(0, $supplier->fresh()->balance_paise);
        $this->assertDatabaseHas('payments', ['direction' => 'in', 'amount_paise' => 10000, 'payable_id' => $id]);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'purchase_cancel', 'qty_delta' => -10]);
        $this->assertSame([], app(StockLedger::class)->findMismatches());
        $this->assertSame([], app(SupplierLedger::class)->findMismatches());

        // A second purchase whose stock has partly gone out cannot be cancelled.
        $id2 = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]], ['supplier_invoice_no' => 'INV-779'])->json('id');
        $this->confirm($id2)->assertOk();
        $batch = StockBatch::orderByDesc('id')->first();
        DB::transaction(fn () => app(StockLedger::class)->record($batch, StockLedger::SELLABLE, -1, 'sale', Purchase::find($id2), $this->owner->id));
        $this->keyedPost((string) Str::uuid(), "/api/v1/purchases/{$id2}/cancel", ['reason' => 'Try'])
            ->assertStatus(409)->assertJsonPath('code', 'purchase_stock_moved');
        $this->assertSame('confirmed', Purchase::find($id2)->status);
    }

    // ---- purchase returns (S11) ---------------------------------------------------------------------------------

    public function test_returning_stock_to_the_supplier_writes_a_debit_note_and_reduces_the_balance(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]])->json('id');
        $this->confirm($id)->assertOk();
        $batch = StockBatch::firstOrFail();

        $this->getJson("/api/v1/purchase-returns/lots?supplier_id={$supplier->id}")->assertOk()->assertJsonPath('data.0.id', $batch->id)->assertJsonPath('data.0.qty_sellable', 10);

        $return = $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', [
            'supplier_id' => $supplier->id, 'reason' => 'Damaged in transit', 'settlement' => 'reduce_balance',
            'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 4]],
        ])->assertCreated();
        // 4 × Rs 40 cost + 5% GST = Rs 168.
        $return->assertJsonPath('return_no', 'D26-27/000001')->assertJsonPath('totals.total_paise', 16800)->assertJsonPath('items.0.qty_base', 4);
        $this->assertSame(6, $batch->fresh()->qty_sellable);
        $this->assertSame(42000 - 16800, $supplier->fresh()->balance_paise);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'purchase_return', 'qty_delta' => -4, 'batch_id' => $batch->id]);
        $this->getJson('/api/v1/purchase-returns/'.$return->json('id'))->assertOk()->assertJsonPath('reason', 'Damaged in transit');

        // More than the batch holds is refused and nothing moves.
        $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', [
            'supplier_id' => $supplier->id, 'reason' => 'Too many', 'settlement' => 'reduce_balance',
            'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 7]],
        ])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(6, $batch->fresh()->qty_sellable);
        $this->assertSame([], app(StockLedger::class)->findMismatches());
    }

    public function test_a_refund_received_for_a_return_is_a_payment_in_and_leaves_the_balance_alone(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000]])->json('id');
        $this->confirm($id, ['amount_paise' => 42000, 'method' => 'cash'])->assertOk(); // fully paid
        $batch = StockBatch::firstOrFail();

        $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', [
            'supplier_id' => $supplier->id, 'reason' => 'Expired on arrival', 'settlement' => 'refund_received', 'refund_method' => 'cash',
            'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 10]],
        ])->assertCreated()->assertJsonPath('totals.total_paise', 42000);
        $this->assertSame(0, $supplier->fresh()->balance_paise);
        $this->assertDatabaseHas('payments', ['direction' => 'in', 'method' => 'cash', 'amount_paise' => 42000, 'party_id' => $supplier->id]);
        $this->assertSame(0, $batch->fresh()->qty_sellable);
    }

    public function test_cancelling_puts_back_the_mrp_the_purchase_changed(): void
    {
        $medicine = $this->medicine();
        $supplier = $this->supplier();
        $id = $this->draft($supplier, [$this->medicineLine($medicine, ['mrp_paise' => 3500])])->json('id');
        $this->confirm($id)->assertOk();
        $this->assertSame(3500, $medicine->fresh()->mrp_paise);

        $this->keyedPost((string) Str::uuid(), "/api/v1/purchases/{$id}/cancel", ['reason' => 'Typed the wrong MRP'])->assertOk();
        $this->assertSame(3000, $medicine->fresh()->mrp_paise);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'mrp', 'old_paise' => 3500, 'new_paise' => 3000, 'source' => 'purchase_cancel']);
    }

    public function test_stock_goes_back_only_to_the_supplier_it_came_from_at_the_rate_it_was_bought_at(): void
    {
        $product = $this->general();
        $seller = $this->supplier();
        $other = $this->supplier(['name' => 'Someone Else', 'gstin' => null]);
        // Billed at 18% on the invoice although the product master says 5%.
        $id = $this->draft($seller, [['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000, 'tax_rate_percent' => 18]])->json('id');
        $this->confirm($id)->assertOk();
        $batch = StockBatch::firstOrFail();

        $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', [
            'supplier_id' => $other->id, 'reason' => 'Wrong supplier picked', 'settlement' => 'reduce_balance',
            'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]],
        ])->assertStatus(409)->assertJsonPath('code', 'batch_not_from_supplier');

        // 2 × Rs 40 cost + 18% = Rs 94.40
        $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', [
            'supplier_id' => $seller->id, 'reason' => 'Short expiry', 'settlement' => 'reduce_balance',
            'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 2]],
        ])->assertCreated()->assertJsonPath('totals.total_paise', 9400);
        $this->assertEqualsWithDelta(18.0, (float) PurchaseReturn::firstOrFail()->items()->firstOrFail()->tax_rate_percent, 0.001);
    }

    public function test_another_shops_documents_are_not_visible(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $id = $this->draft($supplier, [['product_id' => $product->id, 'qty' => 1, 'rate_paise' => 4000]])->json('id');

        $other = Store::create(['name' => 'Other Shop']);
        $stranger = User::factory()->create(['store_id' => $other->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger);
        $this->getJson("/api/v1/purchases/{$id}")->assertNotFound();
        $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertNotFound();
        $this->confirm($id)->assertNotFound();
    }
}
