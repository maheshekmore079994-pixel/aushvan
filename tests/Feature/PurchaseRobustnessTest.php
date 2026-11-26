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
use App\Models\PurchaseItem;
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
use LogicException;
use Tests\TestCase;

/**
 * Tries to break the Phase 4 purchases / suppliers backend: double actions, cross-shop access, limits,
 * frozen documents, ledger and stock invariants after every flow.
 */
class PurchaseRobustnessTest extends TestCase
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

    // ---- helpers (same shape as PurchasesTest) -------------------------------------------------------------------

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function tax(string $name): int
    {
        return TaxRate::where('name', $name)->value('id');
    }

    private function medicine(string $name = 'Crocin 500', ?User $as = null): Product
    {
        return app(SaveProduct::class)->handle($as ?? $this->owner, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('TAB'), 'pack_unit_id' => $this->unit('STRIP'), 'pack_size' => 10,
            'allow_loose_sale' => true, 'mrp_paise' => 3000, 'selling_price_paise' => 3000,
            'medicine' => ['generic_name' => 'Paracetamol'],
        ]);
    }

    private function general(string $name = 'Sugar 1kg', ?User $as = null): Product
    {
        return app(SaveProduct::class)->handle($as ?? $this->owner, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => 5000, 'selling_price_paise' => 4800,
        ]);
    }

    private function supplier(array $extra = []): Supplier
    {
        // Each supplier gets its own Maharashtra (27) GSTIN, or the duplicate check would refuse the second one.
        $response = $this->postJson('/api/v1/suppliers', $extra + ['name' => 'Mahalaxmi Pharma Distributors '.Str::random(4), 'mobile' => '9822000001', 'gstin' => '27'.strtoupper(Str::random(13)), 'payment_terms_days' => 15]);
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

    private function generalLine(Product $product, array $extra = []): array
    {
        return $extra + ['product_id' => $product->id, 'qty' => 10, 'rate_paise' => 4000];
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

    private function pay(Supplier $supplier, int $amount, ?int $purchaseId = null, string $method = 'cash'): TestResponse
    {
        return $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => $amount, 'method' => $method] + ($purchaseId ? ['purchase_id' => $purchaseId] : []));
    }

    private function cancel(int $purchaseId, string $reason = 'Entered twice'): TestResponse
    {
        return $this->keyedPost((string) Str::uuid(), "/api/v1/purchases/{$purchaseId}/cancel", ['reason' => $reason]);
    }

    /** @param list<array{batch_id: int, bucket: string, qty_base: int}> $lines */
    private function return(Supplier $supplier, array $lines, array $extra = []): TestResponse
    {
        return $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', $extra + ['supplier_id' => $supplier->id, 'reason' => 'Damaged in transit', 'settlement' => 'reduce_balance', 'lines' => $lines]);
    }

    /** A confirmed general purchase of 10 pieces at Rs 40 (+5% = Rs 420), returning [purchase id, batch]. */
    private function confirmedGeneral(Supplier $supplier, ?array $payment = null, string $invoice = 'INV-778'): array
    {
        $product = $this->general('Sugar '.Str::random(5));
        $id = $this->draft($supplier, [$this->generalLine($product)], ['supplier_invoice_no' => $invoice])->assertCreated()->json('id');
        $this->confirm($id, $payment)->assertOk();

        return [$id, StockBatch::where('product_id', $product->id)->firstOrFail(), $product];
    }

    private function assertInvariants(): void
    {
        $this->assertSame([], app(StockLedger::class)->findMismatches(), 'stock caches disagree with the movements');
        $this->assertSame([], app(SupplierLedger::class)->findMismatches(), 'supplier balances disagree with the ledger');
    }

    private function assertRefusedByModel(callable $attempt, string $what): void
    {
        try {
            $attempt();
        } catch (LogicException) {
            return;
        }
        $this->fail("$what was allowed by the model guard.");
    }

    /** A second shop with an owner, a general product and a supplier of its own. */
    private function otherShop(): array
    {
        $store = Store::create(['name' => 'Other Shop']);
        $stranger = User::factory()->create(['store_id' => $store->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $product = $this->general('Foreign Sugar', $stranger);
        $supplier = Supplier::create(['ulid' => (string) Str::ulid(), 'store_id' => $store->id, 'name' => 'Foreign Supplier', 'search_text' => 'foreign supplier'])->refresh();

        return [$stranger, $product, $supplier, $store];
    }

    // ---- invariants across the whole life cycle -------------------------------------------------------------------

    public function test_stock_and_supplier_ledgers_stay_consistent_through_confirm_pay_return_and_cancel(): void
    {
        $supplier = $this->supplier(['opening_balance_paise' => 10000]);
        $medicine = $this->medicine();
        $sugar = $this->general();
        $id = $this->draft($supplier, [$this->medicineLine($medicine), $this->generalLine($sugar)])->json('id');
        $this->confirm($id, ['amount_paise' => 20000, 'method' => 'upi'])->assertOk();
        $this->pay($supplier, 30000, $id)->assertCreated();
        $this->pay($supplier, 5000)->assertCreated(); // on account
        $batch = StockBatch::where('product_id', $sugar->id)->firstOrFail();
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 3]])->assertCreated();
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 2]], ['settlement' => 'refund_received', 'refund_method' => 'cash'])->assertCreated();

        [$id2] = $this->confirmedGeneral($supplier, ['amount_paise' => 1000, 'method' => 'cash'], 'INV-779');
        $this->cancel($id2)->assertOk();

        $this->assertInvariants();
        // Balance = opening + purchase 1 - paid now - paid later - on account - reduce_balance return (3 × Rs 42 = Rs 126).
        $total = Purchase::find($id)->total_paise;
        $this->assertSame(10000 + $total - 20000 - 30000 - 5000 - 12600, $supplier->fresh()->balance_paise);
        $this->assertSame(SupplierLedgerEntry::where('supplier_id', $supplier->id)->orderByDesc('id')->value('balance_after_paise'), $supplier->fresh()->balance_paise);
    }

    // ---- double actions -------------------------------------------------------------------------------------------

    public function test_confirming_twice_with_different_keys_makes_stock_and_ledger_only_once(): void
    {
        $supplier = $this->supplier();
        [$id] = $this->confirmedGeneral($supplier, ['amount_paise' => 1000, 'method' => 'cash']);
        $this->confirm($id, ['amount_paise' => 1000, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'purchase_not_draft');
        $this->confirm($id)->assertStatus(409)->assertJsonPath('code', 'purchase_not_draft');

        $this->assertSame(1, StockBatch::count());
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, Payment::count());
        $this->assertSame(1, SupplierLedgerEntry::where('type', 'purchase')->count());
        $this->assertSame('P26-27/000001', Purchase::find($id)->purchase_no);
        $this->assertSame(42000 - 1000, $supplier->fresh()->balance_paise);
        $this->assertInvariants();
    }

    public function test_cancelling_after_a_later_payment_gives_every_payment_back(): void
    {
        $supplier = $this->supplier();
        [$id] = $this->confirmedGeneral($supplier, ['amount_paise' => 10000, 'method' => 'cash']);
        $this->pay($supplier, 5000, $id, 'upi')->assertCreated();
        $this->assertSame(15000, Purchase::find($id)->paid_paise);

        $this->cancel($id)->assertOk()->assertJsonPath('status', 'cancelled');
        $this->assertSame(0, $supplier->fresh()->balance_paise);
        $this->assertSame(2, Payment::where('direction', 'in')->where('payable_id', $id)->count());
        $this->assertSame(15000, (int) Payment::where('direction', 'in')->sum('amount_paise'));
        $this->assertDatabaseHas('payments', ['direction' => 'in', 'method' => 'upi', 'amount_paise' => 5000]);
        $this->assertInvariants();

        // Cancelled is final: no second cancel, no payment, no edit, no delete.
        $this->cancel($id)->assertStatus(409)->assertJsonPath('code', 'purchase_not_confirmed');
        $this->pay($supplier, 100, $id)->assertStatus(409)->assertJsonPath('code', 'purchase_not_payable');
        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $supplier->id, 'lines' => []])->assertStatus(409)->assertJsonPath('code', 'purchase_not_draft');
        $this->deleteJson("/api/v1/purchases/{$id}")->assertStatus(409)->assertJsonPath('code', 'purchase_not_draft');
        $this->assertSame(0, $supplier->fresh()->balance_paise);
    }

    public function test_a_cancelled_purchase_has_nothing_left_to_return_and_a_returned_one_cannot_be_cancelled(): void
    {
        $supplier = $this->supplier();
        [$id, $batch] = $this->confirmedGeneral($supplier);
        $this->cancel($id)->assertOk();
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->assertSame(0, PurchaseReturn::count());
        $this->getJson("/api/v1/purchase-returns/lots?supplier_id={$supplier->id}")->assertOk()->assertJsonCount(0, 'data');

        [$id2, $batch2] = $this->confirmedGeneral($supplier, null, 'INV-779');
        $this->return($supplier, [['batch_id' => $batch2->id, 'bucket' => 'sellable', 'qty_base' => 1]])->assertCreated();
        $this->cancel($id2)->assertStatus(409)->assertJsonPath('code', 'purchase_stock_moved');
        $this->assertSame('confirmed', Purchase::find($id2)->status);
        $this->assertSame(9, $batch2->fresh()->qty_sellable);
        $this->assertInvariants();
    }

    // ---- returns ------------------------------------------------------------------------------------------------

    public function test_a_return_split_over_two_lines_of_one_batch_cannot_take_more_than_it_holds(): void
    {
        $supplier = $this->supplier();
        [, $batch] = $this->confirmedGeneral($supplier);
        $this->return($supplier, [
            ['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 6],
            ['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 6],
        ])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');

        $this->assertSame(10, $batch->fresh()->qty_sellable);
        $this->assertSame(0, PurchaseReturn::count());
        $this->assertSame(0, InventoryMovement::where('type', 'purchase_return')->count());
        $this->assertSame(42000, $supplier->fresh()->balance_paise);
        $this->assertInvariants();

        // Two lines that fit together are fine.
        $this->return($supplier, [
            ['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 6],
            ['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 4],
        ])->assertCreated()->assertJsonCount(2, 'items');
        $this->assertSame(0, $batch->fresh()->qty_sellable);
        $this->assertInvariants();
    }

    public function test_a_return_from_the_unsellable_bucket_takes_only_what_that_bucket_holds(): void
    {
        $supplier = $this->supplier();
        [$id, $batch] = $this->confirmedGeneral($supplier);
        DB::transaction(function () use ($batch, $id) {
            $purchase = Purchase::find($id);
            app(StockLedger::class)->record($batch, StockLedger::SELLABLE, -3, 'adjustment', $purchase, $this->owner->id, 'Damaged');
            app(StockLedger::class)->record($batch, StockLedger::UNSELLABLE, 3, 'adjustment', $purchase, $this->owner->id, 'Damaged');
        });
        $this->getJson("/api/v1/purchase-returns/lots?supplier_id={$supplier->id}")->assertOk()->assertJsonPath('data.0.qty_unsellable', 3)->assertJsonPath('data.0.qty_sellable', 7);

        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'unsellable', 'qty_base' => 4]])->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $return = $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'unsellable', 'qty_base' => 3]])->assertCreated();
        $return->assertJsonPath('items.0.bucket', 'unsellable')->assertJsonPath('totals.total_paise', 12600); // 3 × Rs 40 + 5%
        $this->assertSame(0, $batch->fresh()->qty_unsellable);
        $this->assertSame(7, $batch->fresh()->qty_sellable);
        $this->assertDatabaseHas('inventory_movements', ['type' => 'purchase_return', 'bucket' => 'unsellable', 'qty_delta' => -3]);
        $this->assertSame(42000 - 12600, $supplier->fresh()->balance_paise);
        $this->assertInvariants();
    }

    public function test_return_validation_needs_a_refund_method_a_known_bucket_and_a_positive_quantity(): void
    {
        $supplier = $this->supplier();
        [, $batch] = $this->confirmedGeneral($supplier);
        $line = ['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1];
        $this->return($supplier, [$line], ['settlement' => 'refund_received'])->assertStatus(422);
        $this->return($supplier, [$line], ['settlement' => 'keep'])->assertStatus(422);
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'damaged', 'qty_base' => 1]])->assertStatus(422);
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 0]])->assertStatus(422);
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => -1]])->assertStatus(422);
        $this->return($supplier, [])->assertStatus(422);
        $this->return($supplier, [$line], ['reason' => 'ab'])->assertStatus(422);
        $this->postJson('/api/v1/purchase-returns', ['supplier_id' => $supplier->id, 'reason' => 'No key', 'settlement' => 'reduce_balance', 'lines' => [$line]], ['Idempotency-Key' => ''])->assertStatus(400);
        $this->assertSame(10, $batch->fresh()->qty_sellable);
        $this->assertSame(0, PurchaseReturn::count());
    }

    // ---- other shops, inactive and deleted parties ---------------------------------------------------------------

    public function test_another_shops_product_batch_or_supplier_never_enters_this_shops_documents(): void
    {
        [$stranger, $foreignProduct, $foreignSupplier] = $this->otherShop();
        $supplier = $this->supplier();

        // Their product on our draft or preview: refused as missing, never priced.
        $this->postJson('/api/v1/purchases/preview', ['supplier_id' => $supplier->id, 'lines' => [$this->generalLine($foreignProduct)]])
            ->assertOk()->assertJsonPath('errors.0.code', 'product_missing')->assertJsonCount(0, 'lines');
        $this->draft($supplier, [$this->generalLine($foreignProduct)])->assertStatus(409)->assertJsonPath('code', 'product_missing');
        $this->assertSame(0, Purchase::count());

        // Their supplier on our draft, payment or return.
        $this->draft($foreignSupplier, [$this->generalLine($this->general())])->assertNotFound();
        $this->pay($foreignSupplier, 100)->assertNotFound();
        $this->return($foreignSupplier, [['batch_id' => 1, 'bucket' => 'sellable', 'qty_base' => 1]])->assertNotFound();

        // Our batch on their return: missing, not moved. Our lots: invisible to them.
        [$id, $batch] = $this->confirmedGeneral($supplier);
        $this->actingAs($stranger);
        $this->keyedPost((string) Str::uuid(), '/api/v1/purchase-returns', ['supplier_id' => $foreignSupplier->id, 'reason' => 'Steal', 'settlement' => 'reduce_balance', 'lines' => [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]]])
            ->assertStatus(409)->assertJsonPath('code', 'batch_missing');
        $this->getJson('/api/v1/purchase-returns/lots')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/purchases')->assertOk()->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/suppliers')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $foreignSupplier->id);
        $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$foreignSupplier->id}/payments", ['amount_paise' => 100, 'method' => 'cash', 'purchase_id' => $id])->assertNotFound();
        $this->cancel($id)->assertNotFound();
        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $foreignSupplier->id, 'lines' => []])->assertNotFound();
        $this->deleteJson("/api/v1/purchases/{$id}")->assertNotFound();

        $this->assertSame(10, $batch->fresh()->qty_sellable);
        $this->assertSame('confirmed', Purchase::find($id)->status);
        $this->assertSame(0, $foreignSupplier->fresh()->balance_paise);
        $this->assertInvariants();
    }

    public function test_an_inactive_product_or_supplier_and_a_deleted_supplier_are_refused_for_a_new_draft(): void
    {
        $supplier = $this->supplier();
        $product = $this->general();
        $product->update(['is_active' => false]);
        $this->draft($supplier, [$this->generalLine($product)])->assertStatus(409)->assertJsonPath('code', 'product_missing');

        $live = $this->general('Rice 1kg');
        $supplier->update(['is_active' => false]);
        $this->draft($supplier, [$this->generalLine($live)])->assertNotFound();

        $deleted = $this->supplier(['name' => 'Gone Traders']);
        $deleted->delete();
        $this->draft($deleted, [$this->generalLine($live)])->assertNotFound();
        $this->pay($deleted, 100)->assertNotFound();
        $this->getJson("/api/v1/suppliers/{$deleted->id}")->assertNotFound();
        $this->assertSame(0, Purchase::count());
    }

    // ---- pricing edges ------------------------------------------------------------------------------------------

    public function test_a_free_line_and_a_fully_discounted_line_cost_nothing_and_still_confirm(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $sugar = $this->general();
        $response = $this->draft($supplier, [
            $this->medicineLine($medicine, ['rate_paise' => 0, 'discount_percent' => 0]),
            $this->generalLine($sugar, ['discount_percent' => 100]),
        ])->assertCreated();
        $response->assertJsonPath('totals.total_paise', 0)
            ->assertJsonPath('totals.gross_paise', 40000)
            ->assertJsonPath('items.0.cost_rate', '0.000000')
            ->assertJsonPath('items.1.discount_paise', 40000)
            ->assertJsonPath('items.1.taxable_paise', 0)
            ->assertJsonPath('items.1.tax_paise', 0);
        $id = $response->json('id');
        $this->assertSame(40000, PurchaseItem::where('purchase_id', $id)->where('line_no', 2)->value('gross_paise'));

        $this->confirm($id, ['amount_paise' => 1, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'overpaid');
        // Free replacement stock is a real delivery: it must confirm (and not crash on a zero ledger entry).
        $this->confirm($id)->assertOk()->assertJsonPath('total_paise', 0)->assertJsonPath('status', 'confirmed');
        $this->assertSame(110, StockBatch::where('product_id', $medicine->id)->value('qty_sellable'));
        $this->assertSame(10, StockBatch::where('product_id', $sugar->id)->value('qty_sellable'));
        $this->assertSame('0.000000', (string) StockBatch::where('product_id', $sugar->id)->value('cost_rate'));
        $this->assertDatabaseHas('product_price_history', ['product_id' => $sugar->id, 'field' => 'cost', 'new_paise' => 0, 'source' => 'purchase']);
        $this->assertSame(0, $supplier->fresh()->balance_paise);
        $this->getJson('/api/v1/purchases?status=due')->assertOk()->assertJsonCount(0, 'data');
        $this->assertInvariants();
    }

    public function test_quantities_and_rates_at_the_validation_limits(): void
    {
        $supplier = $this->supplier();
        $sugar = $this->general();
        $big = $this->draft($supplier, [$this->generalLine($sugar, ['qty' => 10000000, 'free_qty' => 10000000, 'rate_paise' => 100000000])])->assertCreated();
        // 10,000,000 × Rs 10,00,000 = Rs 1,00,00,00,00,00,000 gross, +5%, shared over 20,000,000 pieces.
        $big->assertJsonPath('totals.gross_paise', 1000000000000000)->assertJsonPath('items.0.qty_base', 20000000)->assertJsonPath('totals.total_paise', 1050000000000000);
        $this->assertSame('50000000.000000', $big->json('items.0.cost_rate'));
        $this->confirm($big->json('id'))->assertOk();
        $this->assertSame(20000000, StockBatch::where('product_id', $sugar->id)->value('qty_sellable'));
        $this->assertSame(1050000000000000, $supplier->fresh()->balance_paise);
        $this->assertInvariants();

        $this->draft($supplier, [$this->generalLine($sugar, ['qty' => 10000001])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['free_qty' => 10000001])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['rate_paise' => 100000001])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['rate_paise' => -1])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['qty' => -1])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['discount_percent' => 100.01])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['mrp_paise' => -1])])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['selling_price_paise' => 0])])->assertStatus(422);
        $this->draft($supplier, array_fill(0, 201, $this->generalLine($sugar)))->assertStatus(422);
        $this->draft($supplier, [], ['other_charges_paise' => 10000001])->assertStatus(422);
        $this->draft($supplier, [$this->generalLine($sugar, ['qty' => 0, 'free_qty' => 0])], ['supplier_invoice_no' => 'INV-779'])->assertStatus(409)->assertJsonPath('code', 'qty_required');
        $this->assertSame(1, Purchase::count());
    }

    public function test_base_unit_lines_and_products_without_a_pack_unit_are_counted_in_base_units(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $sugar = $this->general();
        $response = $this->draft($supplier, [
            $this->medicineLine($medicine, ['unit' => 'base', 'qty' => 25, 'free_qty' => 0, 'rate_paise' => 200, 'discount_percent' => 0]),
            $this->generalLine($sugar, ['unit' => 'pack', 'qty' => 3, 'free_qty' => 1]),
        ])->assertCreated();
        $response->assertJsonPath('items.0.unit_code', 'TAB')->assertJsonPath('items.0.unit_factor', 1)->assertJsonPath('items.0.qty_base', 25)
            ->assertJsonPath('items.0.taxable_paise', 5000)
            ->assertJsonPath('items.1.unit_code', 'PCS')->assertJsonPath('items.1.unit_factor', 1)->assertJsonPath('items.1.qty_base', 4)
            ->assertJsonPath('items.1.taxable_paise', 12000)
            ->assertJsonPath('totals.gross_paise', 17000);
        // 25 tablets for Rs 50: 200 paise a tablet, so a strip of 10 costs Rs 20.
        $this->assertSame('200.000000', $response->json('items.0.cost_rate'));
        $this->confirm($response->json('id'))->assertOk();
        $this->assertSame(25, StockBatch::where('product_id', $medicine->id)->value('qty_sellable'));
        $this->assertSame(4, StockBatch::where('product_id', $sugar->id)->value('qty_sellable'));
        // The cost history is kept per pricing unit (a strip of 10) whatever unit the supplier billed in.
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'cost', 'new_paise' => 2000, 'source' => 'purchase']);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $sugar->id, 'field' => 'cost', 'new_paise' => 3000, 'source' => 'purchase']); // Rs 120 over 4 pieces
        $this->assertInvariants();
    }

    public function test_expiry_this_month_is_accepted_but_a_draft_that_expires_before_it_is_confirmed_is_refused(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $this->draft($supplier, [$this->medicineLine($medicine, ['expiry_month' => '2026-10'])])->assertStatus(409)->assertJsonPath('code', 'already_expired');
        $this->draft($supplier, [$this->medicineLine($medicine, ['expiry_month' => '2026-13'])])->assertStatus(422);
        $id = $this->draft($supplier, [$this->medicineLine($medicine, ['expiry_month' => '2026-11'])])->assertCreated()->assertJsonPath('items.0.expiry_date', '2026-11-30')->json('id');

        Carbon::setTestNow(Carbon::parse('2026-12-01 09:00', 'Asia/Kolkata'));
        $this->confirm($id)->assertStatus(409)->assertJsonPath('code', 'already_expired');
        $this->assertSame('draft', Purchase::find($id)->status);
        $this->assertNull(Purchase::find($id)->purchase_no);
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, SupplierLedgerEntry::count());
        $this->assertSame(0, DB::table('product_price_history')->where('source', 'purchase')->count());
        $this->assertSame(0, $supplier->fresh()->balance_paise);
    }

    // ---- prices follow the pack (D-061) ---------------------------------------------------------------------------

    public function test_a_lower_mrp_on_a_later_purchase_also_follows_and_caps_the_selling_price(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $first = $this->draft($supplier, [$this->medicineLine($medicine, ['mrp_paise' => 3200])])->json('id');
        $this->confirm($first)->assertOk();
        $this->assertSame(3200, $medicine->fresh()->mrp_paise);
        $this->assertSame(3000, $medicine->fresh()->selling_price_paise); // still under the MRP, untouched

        $second = $this->draft($supplier, [$this->medicineLine($medicine, ['mrp_paise' => 2800, 'batch_no' => 'B502'])], ['supplier_invoice_no' => 'INV-779'])->json('id');
        $this->confirm($second)->assertOk();
        $product = $medicine->fresh();
        $this->assertSame(2800, $product->mrp_paise);
        $this->assertSame(2800, $product->selling_price_paise);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'mrp', 'old_paise' => 3200, 'new_paise' => 2800, 'source' => 'purchase']);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'selling_price', 'old_paise' => 3000, 'new_paise' => 2800, 'source' => 'purchase']);
        $this->assertSame(2, DB::table('product_price_history')->where('product_id', $medicine->id)->where('field', 'cost')->count());

        // Each batch keeps its own pack prices.
        $this->assertSame(3200, StockBatch::where('batch_no', 'B501')->value('mrp_paise'));
        $this->assertSame(3000, StockBatch::where('batch_no', 'B501')->value('selling_price_paise'));
        $this->assertSame(2800, StockBatch::where('batch_no', 'B502')->value('mrp_paise'));
        $this->assertSame(2800, StockBatch::where('batch_no', 'B502')->value('selling_price_paise'));
        $this->assertSame($product->version, $medicine->version + 2);
    }

    public function test_an_explicit_selling_price_goes_to_the_batch_and_the_product_but_never_above_the_mrp(): void
    {
        $supplier = $this->supplier();
        $medicine = $this->medicine();
        $this->draft($supplier, [$this->medicineLine($medicine, ['mrp_paise' => 3200, 'selling_price_paise' => 3201])])->assertStatus(409)->assertJsonPath('code', 'price_above_mrp');
        $id = $this->draft($supplier, [$this->medicineLine($medicine, ['mrp_paise' => 3200, 'selling_price_paise' => 2500])])->assertCreated()->json('id');
        $this->confirm($id)->assertOk();
        $this->assertSame(2500, StockBatch::firstOrFail()->selling_price_paise);
        $this->assertSame(2500, $medicine->fresh()->selling_price_paise);
        $this->assertSame(3200, $medicine->fresh()->mrp_paise);
        $this->assertDatabaseHas('product_price_history', ['product_id' => $medicine->id, 'field' => 'selling_price', 'old_paise' => 3000, 'new_paise' => 2500, 'source' => 'purchase']);

        // A general line with a lower MRP than the product's selling price caps the selling price too.
        $sugar = $this->general();
        $this->assertSame(4800, $sugar->selling_price_paise);
        $this->confirm($this->draft($supplier, [$this->generalLine($sugar, ['mrp_paise' => 4500])], ['supplier_invoice_no' => 'INV-780'])->json('id'))->assertOk();
        $this->assertSame(4500, $sugar->fresh()->mrp_paise);
        $this->assertSame(4500, $sugar->fresh()->selling_price_paise);
    }

    // ---- drafts -------------------------------------------------------------------------------------------------

    public function test_saving_a_draft_again_replaces_its_lines_and_leaves_no_orphan_rows(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier(['name' => 'Second Agency', 'gstin' => '27ZZZZZ1234F1Z5', 'payment_terms_days' => 0]);
        $medicine = $this->medicine();
        $sugar = $this->general();
        $id = $this->draft($supplier, [$this->medicineLine($medicine), $this->generalLine($sugar)])->assertJsonCount(2, 'items')->json('id');
        $this->assertSame(2, PurchaseItem::count());

        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $other->id, 'supplier_invoice_no' => 'INV-900', 'lines' => [$this->generalLine($sugar, ['qty' => 7])]])
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.line_no', 1)->assertJsonPath('items.0.qty', 7)
            ->assertJsonPath('supplier.id', $other->id)->assertJsonPath('due_date', null)->assertJsonPath('totals.total_paise', 29400);
        $this->assertSame(1, PurchaseItem::count());
        $this->assertSame(1, PurchaseItem::where('purchase_id', $id)->count());

        // A faulty replacement keeps the old lines (the change is one transaction).
        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $other->id, 'lines' => [$this->medicineLine($medicine, ['batch_no' => ''])]])->assertStatus(409)->assertJsonPath('code', 'batch_required');
        $this->assertSame(1, PurchaseItem::where('purchase_id', $id)->where('qty', 7)->count());
        $this->putJson("/api/v1/purchases/{$id}", ['supplier_id' => $other->id, 'lines' => []])->assertStatus(422);
        $this->assertSame(1, PurchaseItem::where('purchase_id', $id)->count());

        $this->deleteJson("/api/v1/purchases/{$id}")->assertNoContent();
        $this->assertSame(0, PurchaseItem::count());
        $this->assertSame(0, Purchase::count());
        $this->deleteJson("/api/v1/purchases/{$id}")->assertNotFound();
    }

    // ---- model guards -------------------------------------------------------------------------------------------

    public function test_model_guards_freeze_confirmed_purchases_their_lines_ledger_entries_and_debit_notes(): void
    {
        $supplier = $this->supplier(['opening_balance_paise' => 500]);
        [$id, $batch] = $this->confirmedGeneral($supplier);
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]])->assertCreated();
        $purchase = Purchase::findOrFail($id);
        $item = PurchaseItem::where('purchase_id', $id)->firstOrFail();
        $entry = SupplierLedgerEntry::firstOrFail();
        $return = PurchaseReturn::firstOrFail();
        $returnItem = $return->items()->firstOrFail();

        $this->assertRefusedByModel(fn () => $purchase->update(['total_paise' => 1]), 'changing a confirmed total');
        $this->assertRefusedByModel(fn () => $purchase->update(['supplier_id' => $supplier->id + 1]), 'moving a confirmed purchase to another supplier');
        $this->assertRefusedByModel(fn () => $purchase->delete(), 'deleting a confirmed purchase');
        $this->assertRefusedByModel(fn () => $item->update(['qty' => 1]), 'changing a confirmed line');
        $this->assertRefusedByModel(fn () => $item->delete(), 'deleting a confirmed line');
        $this->assertRefusedByModel(fn () => $entry->update(['credit_paise' => 1]), 'changing a ledger entry');
        $this->assertRefusedByModel(fn () => $entry->delete(), 'deleting a ledger entry');
        $this->assertRefusedByModel(fn () => $return->update(['total_paise' => 1]), 'changing a debit note');
        $this->assertRefusedByModel(fn () => $return->delete(), 'deleting a debit note');
        $this->assertRefusedByModel(fn () => $returnItem->update(['qty_base' => 99]), 'changing a debit note line');
        $this->assertRefusedByModel(fn () => $returnItem->delete(), 'deleting a debit note line');
        $this->assertRefusedByModel(fn () => DB::transaction(fn () => app(SupplierLedger::class)->post($supplier, 'adjustment', 0, 0, null, $this->owner->id)), 'a zero ledger entry');
        $this->assertRefusedByModel(fn () => DB::transaction(fn () => app(SupplierLedger::class)->post($supplier, 'adjustment', -1, 0, null, $this->owner->id)), 'a negative ledger entry');

        $this->assertSame(42000 - 4200 + 500, $supplier->fresh()->balance_paise);
        $this->assertSame(10, PurchaseItem::find($item->id)->qty);
        $this->assertDatabaseHas('purchases', ['id' => $id, 'status' => 'confirmed']);
        $this->assertInvariants();
    }

    // ---- supplier payments --------------------------------------------------------------------------------------

    public function test_payment_validation_refuses_zero_negative_wrong_and_foreign_purchases(): void
    {
        $supplier = $this->supplier();
        $otherSupplier = $this->supplier(['name' => 'Second Agency']);
        [$id] = $this->confirmedGeneral($supplier);
        [$otherId] = $this->confirmedGeneral($otherSupplier, null, 'INV-900');
        $draftId = $this->draft($supplier, [$this->generalLine($this->general('Rice'))], ['supplier_invoice_no' => 'INV-901'])->json('id');

        $this->pay($supplier, 0, $id)->assertStatus(422);
        $this->pay($supplier, -100, $id)->assertStatus(422);
        $this->pay($supplier, 1000000001)->assertStatus(422);
        $this->pay($supplier, 100, $id, 'bitcoin')->assertStatus(422);
        $this->pay($supplier, 100, $otherId)->assertStatus(409)->assertJsonPath('code', 'purchase_not_payable');
        $this->pay($supplier, 100, $draftId)->assertStatus(409)->assertJsonPath('code', 'purchase_not_payable');
        $this->pay($supplier, 100, 999999)->assertNotFound();
        $this->keyedPost((string) Str::uuid(), "/api/v1/suppliers/{$supplier->id}/payments", ['amount_paise' => 100, 'method' => 'cash', 'purchase_id' => 'abc'])->assertStatus(422);

        $this->assertSame(0, Payment::count());
        $this->assertSame(42000, $supplier->fresh()->balance_paise);
        $this->assertSame(42000, $otherSupplier->fresh()->balance_paise);
        $this->assertSame(0, Purchase::find($otherId)->paid_paise);

        // Exactly the amount left is fine; one paisa more is not; the amounts paid now and later add up.
        $this->pay($supplier, 42000, $id)->assertCreated()->assertJsonPath('balance_paise', 0);
        $this->pay($supplier, 1, $id)->assertStatus(409)->assertJsonPath('code', 'overpaid')->assertJsonPath('due_paise', 0);
        $this->assertSame(42000, Purchase::find($id)->paid_paise);
        $this->getJson('/api/v1/purchases?status=due')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $otherId);
        $this->assertInvariants();
    }

    public function test_confirm_payment_validation_refuses_bad_methods_negative_amounts_and_missing_fields(): void
    {
        $supplier = $this->supplier();
        $id = $this->draft($supplier, [$this->generalLine($this->general())])->json('id');
        $this->confirm($id, ['amount_paise' => -1, 'method' => 'cash'])->assertStatus(422);
        $this->confirm($id, ['amount_paise' => 100, 'method' => 'cheque-ish'])->assertStatus(422);
        $this->confirm($id, ['amount_paise' => 100])->assertStatus(422);
        $this->confirm($id, ['method' => 'cash'])->assertStatus(422);
        $this->confirm($id, ['amount_paise' => 100, 'method' => 'cash', 'reference_no' => str_repeat('x', 51)])->assertStatus(422);
        $this->postJson("/api/v1/purchases/{$id}/confirm", [], ['Idempotency-Key' => ''])->assertStatus(400);
        $this->assertSame('draft', Purchase::find($id)->status);
        $this->assertSame(0, StockBatch::count());

        $this->confirm($id, ['amount_paise' => 0, 'method' => 'cash'])->assertOk()->assertJsonPath('paid_paise', 0)->assertJsonCount(0, 'payments');
        $this->assertSame(0, Payment::count());
        $this->assertInvariants();
    }

    // ---- permissions --------------------------------------------------------------------------------------------

    public function test_a_cashier_is_refused_on_every_supplier_and_purchase_route(): void
    {
        $supplier = $this->supplier();
        [$id, $batch] = $this->confirmedGeneral($supplier);
        $draftId = $this->draft($supplier, [$this->generalLine($this->general('Rice'))], ['supplier_invoice_no' => 'INV-901'])->json('id');
        $contactId = $this->postJson("/api/v1/suppliers/{$supplier->id}/contacts", ['name' => 'Rahul'])->assertCreated()->json('id');
        $returnId = $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]])->json('id');
        $balance = $supplier->fresh()->balance_paise;

        $this->actingAs($this->userWithRole(RoleName::Cashier));
        $this->getJson('/api/v1/suppliers')->assertForbidden();
        $this->getJson('/api/v1/suppliers/visits')->assertForbidden();
        $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertForbidden();
        $this->postJson('/api/v1/suppliers', ['name' => 'Y'])->assertForbidden();
        $this->putJson("/api/v1/suppliers/{$supplier->id}", ['name' => 'Y'])->assertForbidden();
        $this->postJson("/api/v1/suppliers/{$supplier->id}/contacts", ['name' => 'Y'])->assertForbidden();
        $this->putJson("/api/v1/suppliers/{$supplier->id}/contacts/{$contactId}", ['name' => 'Y'])->assertForbidden();
        $this->deleteJson("/api/v1/suppliers/{$supplier->id}/contacts/{$contactId}")->assertForbidden();
        $this->pay($supplier, 100)->assertForbidden();
        $this->getJson('/api/v1/purchases')->assertForbidden();
        $this->postJson('/api/v1/purchases/preview', ['lines' => []])->assertForbidden();
        $this->draft($supplier, [])->assertForbidden();
        $this->getJson("/api/v1/purchases/{$id}")->assertForbidden();
        $this->putJson("/api/v1/purchases/{$draftId}", ['supplier_id' => $supplier->id, 'lines' => []])->assertForbidden();
        $this->deleteJson("/api/v1/purchases/{$draftId}")->assertForbidden();
        $this->confirm($draftId)->assertForbidden();
        $this->cancel($id)->assertForbidden();
        $this->getJson('/api/v1/purchase-returns/lots')->assertForbidden();
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]])->assertForbidden();
        $this->getJson("/api/v1/purchase-returns/{$returnId}")->assertForbidden();

        $this->assertSame('draft', Purchase::find($draftId)->status);
        $this->assertSame('confirmed', Purchase::find($id)->status);
        $this->assertSame(9, $batch->fresh()->qty_sellable);
        $this->assertSame($balance, $supplier->fresh()->balance_paise);
        $this->assertDatabaseHas('supplier_contacts', ['id' => $contactId, 'is_active' => true, 'name' => 'Rahul']);
    }

    public function test_unauthenticated_requests_are_refused_everywhere(): void
    {
        $supplier = $this->supplier();
        [$id, $batch] = $this->confirmedGeneral($supplier);

        // Drop the acting-as guards: the next requests carry no session and no token.
        $this->app['auth']->forgetGuards();
        $this->flushSession();

        $this->getJson('/api/v1/suppliers')->assertUnauthorized();
        $this->getJson("/api/v1/suppliers/{$supplier->id}")->assertUnauthorized();
        $this->postJson('/api/v1/suppliers', ['name' => 'Y'])->assertUnauthorized();
        $this->pay($supplier, 100)->assertUnauthorized();
        $this->getJson('/api/v1/purchases')->assertUnauthorized();
        $this->getJson("/api/v1/purchases/{$id}")->assertUnauthorized();
        $this->draft($supplier, [])->assertUnauthorized();
        $this->confirm($id)->assertUnauthorized();
        $this->cancel($id)->assertUnauthorized();
        $this->getJson('/api/v1/purchase-returns/lots')->assertUnauthorized();
        $this->return($supplier, [['batch_id' => $batch->id, 'bucket' => 'sellable', 'qty_base' => 1]])->assertUnauthorized();
        $this->assertSame(10, $batch->fresh()->qty_sellable);
        $this->assertSame('confirmed', Purchase::find($id)->status);
    }

    // ---- suppliers ----------------------------------------------------------------------------------------------

    public function test_visits_are_ordered_by_day_then_name_and_leave_out_inactive_and_dayless_contacts(): void
    {
        $supplier = $this->supplier();
        $other = $this->supplier(['name' => 'Second Agency']);
        $add = fn (Supplier $s, string $name, ?int $day) => $this->postJson("/api/v1/suppliers/{$s->id}/contacts", ['name' => $name, 'visit_day' => $day])->assertCreated()->json('id');
        $add($supplier, 'Zubin', 5);
        $add($other, 'Bhavesh', 1);
        $add($supplier, 'Amol', 1);
        $add($supplier, 'Nobody', null);
        $removed = $add($other, 'Gone', 0);
        $this->deleteJson("/api/v1/suppliers/{$other->id}/contacts/{$removed}")->assertNoContent();
        $this->postJson("/api/v1/suppliers/{$supplier->id}/contacts", ['name' => 'Bad', 'visit_day' => 7])->assertStatus(422);
        // A contact may not be moved to another supplier's URL.
        $this->putJson("/api/v1/suppliers/{$supplier->id}/contacts/{$removed}", ['name' => 'Hijack'])->assertNotFound();

        $visits = $this->getJson('/api/v1/suppliers/visits')->assertOk()->assertJsonCount(3, 'data');
        $this->assertSame(['Amol', 'Bhavesh', 'Zubin'], array_column($visits->json('data'), 'name'));
        $this->assertSame([1, 1, 5], array_column($visits->json('data'), 'visit_day'));
        $this->assertSame($other->id, $visits->json('data.1.supplier.id'));
        $this->getJson("/api/v1/suppliers/{$other->id}")->assertOk()->assertJsonCount(1, 'contacts')->assertJsonPath('contacts.0.name', 'Bhavesh');
    }

    public function test_supplier_validation_and_opening_balance_edges(): void
    {
        $this->postJson('/api/v1/suppliers', ['name' => 'A', 'opening_balance_paise' => -1])->assertStatus(422);
        $this->postJson('/api/v1/suppliers', ['name' => 'A', 'mobile' => '12345'])->assertStatus(422);
        $this->postJson('/api/v1/suppliers', ['name' => 'A', 'payment_terms_days' => 366])->assertStatus(422);
        $this->postJson('/api/v1/suppliers', ['name' => 'A', 'kind' => 'friend'])->assertStatus(422);
        $this->postJson('/api/v1/suppliers', ['name' => ''])->assertStatus(422);
        $this->assertSame(0, Supplier::count());

        $zero = $this->supplier(['opening_balance_paise' => 0, 'gstin' => '27ABCDE1234F1Z5']);
        $this->assertSame(0, SupplierLedgerEntry::where('supplier_id', $zero->id)->count());
        // A GSTIN typed in lower case is stored in upper case; a malformed one is refused.
        $lower = $this->postJson('/api/v1/suppliers', ['name' => 'Lower Case Gst', 'gstin' => '24abcde1234f1z5'])->assertCreated()->assertJsonPath('gstin', '24ABCDE1234F1Z5');
        $this->assertSame('24ABCDE1234F1Z5', Supplier::find($lower->json('id'))->gstin);
        $this->postJson('/api/v1/suppliers', ['name' => 'Short Gst', 'gstin' => '24ABCDE1234F1Z'])->assertStatus(422);
        $this->postJson('/api/v1/suppliers', ['name' => 'Odd Gst', 'gstin' => 'ABABCDE1234F1Z5'])->assertStatus(422);
        $same = $this->postJson('/api/v1/suppliers', ['name' => 'Different Name', 'gstin' => '27ABCDE1234F1Z5'])->assertStatus(409)->assertJsonPath('code', 'possible_duplicate');
        $this->assertSame($zero->id, $same->json('duplicates.0.id'));
        $this->assertInvariants();
    }

    // ---- numbering and the duplicate-invoice check -------------------------------------------------------------

    public function test_a_cancelled_purchase_does_not_block_its_invoice_number_and_the_sequence_continues(): void
    {
        $supplier = $this->supplier();
        [$id] = $this->confirmedGeneral($supplier, null, 'INV-500');
        $this->assertSame('P26-27/000001', Purchase::find($id)->purchase_no);
        $this->draft($supplier, [$this->generalLine($this->general('Rice'))], ['supplier_invoice_no' => 'INV-500'])->assertStatus(409)->assertJsonPath('code', 'duplicate_supplier_invoice');
        $this->cancel($id)->assertOk();

        $again = $this->draft($supplier, [$this->generalLine($this->general('Rice again'))], ['supplier_invoice_no' => 'INV-500'])->assertCreated()->json('id');
        $this->assertNull(Purchase::find($again)->purchase_no);
        $this->confirm($again)->assertOk()->assertJsonPath('purchase_no', 'P26-27/000002');
        // The cancelled one keeps its number; another shop's supplier with the same invoice number is no duplicate.
        $this->assertSame('P26-27/000001', Purchase::find($id)->purchase_no);
        $other = $this->supplier(['name' => 'Second Agency']);
        $dal = $this->general('Dal');
        $this->draft($other, [$this->generalLine($dal)], ['supplier_invoice_no' => 'INV-500'])->assertCreated();
        // Drafts count as entered too.
        $this->draft($other, [$this->generalLine($dal)], ['supplier_invoice_no' => 'INV-500'])->assertStatus(409);
        $this->getJson('/api/v1/purchases?status=cancelled')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $id);
        $this->getJson('/api/v1/purchases?search=INV-500')->assertOk()->assertJsonCount(3, 'data');
        $this->getJson('/api/v1/purchases?status=paid')->assertStatus(422);
        $this->assertInvariants();
    }
}
