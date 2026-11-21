<?php

namespace Tests\Feature;

use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Shared\Rate;
use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\DrugSchedule;
use App\Models\InventoryMovement;
use App\Models\Product;
use App\Models\StockAdjustment;
use App\Models\StockBatch;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use LogicException;
use Tests\TestCase;

class StockTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private int $key = 0;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-11 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->actingAs($this->owner);
    }

    private function medicine(string $name = 'Crocin 500 Tablet'): Product
    {
        return app(SaveProduct::class)->handle($this->owner, [
            'type' => 'medical', 'name' => $name,
            'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => Unit::where('code', 'TAB')->value('id'),
            'pack_unit_id' => Unit::where('code', 'STRIP')->value('id'),
            'pack_size' => 10, 'allow_loose_sale' => true,
            'mrp_paise' => 3000, 'selling_price_paise' => 3000, 'min_stock' => 5,
            'medicine' => ['drug_schedule_id' => DrugSchedule::where('code', 'NONE')->value('id')],
        ]);
    }

    private function sugar(): Product
    {
        return app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => 'Sugar (loose)',
            'tax_rate_id' => TaxRate::where('name', 'GST 5%')->value('id'),
            'base_unit_id' => Unit::where('code', 'G')->value('id'),
            'pack_unit_id' => Unit::where('code', 'KG')->value('id'),
            'pack_size' => 1000, 'allow_loose_sale' => true, 'selling_price_paise' => 4400, 'min_stock' => 10,
        ]);
    }

    private function headers(): array
    {
        return ['Idempotency-Key' => 'key-'.(++$this->key)];
    }

    private function addStock(Product $product, array $item, array $headers = []): TestResponse
    {
        return $this->postJson("/api/v1/products/{$product->id}/opening-stock", ['items' => [$item]], $headers ?: $this->headers());
    }

    public function test_rate_keeps_a_box_of_twelve_exact(): void
    {
        $rate = Rate::perBase(10000, 12); // Rs 100 for 12 pieces

        $this->assertSame('833.333333', $rate);
        $this->assertSame(10000, Rate::amount($rate, 12));
        $this->assertSame(833, Rate::amount($rate, 1));
        $this->assertSame(4167, Rate::amount($rate, 5));
    }

    public function test_opening_stock_creates_a_batch_and_a_ledger_entry(): void
    {
        $product = $this->medicine();

        $this->addStock($product, ['qty' => 12, 'cost_paise' => 2340, 'mrp_paise' => 3000, 'batch_no' => 'RQ3549', 'expiry_month' => '2028-05'])
            ->assertCreated();

        $batch = StockBatch::sole();
        $this->assertSame(120, $batch->qty_sellable); // 12 strips of 10
        $this->assertSame('2028-05-31', $batch->expiry_date->toDateString()); // good to the end of the printed month
        $this->assertSame('234.000000', $batch->cost_rate);

        $movement = InventoryMovement::sole();
        $this->assertSame(['opening', 'sellable', 120, 120], [$movement->type, $movement->bucket, $movement->qty_delta, $movement->balance_after]);
        $this->assertSame((new StockAdjustment)->getMorphClass(), $movement->source_type);
        $this->assertSame($this->owner->id, $movement->user_id);

        $this->getJson("/api/v1/products/{$product->id}")
            ->assertJsonPath('stock_qty', 120)
            ->assertJsonPath('stock_status', 'ok')
            ->assertJsonPath('batches.0.batch_no', 'RQ3549')
            ->assertJsonPath('batches.0.cost_paise', 2340)
            ->assertJsonPath('batches.0.value_paise', 28080);
    }

    public function test_medicine_stock_needs_batch_and_expiry_and_cannot_be_expired(): void
    {
        $product = $this->medicine();

        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'expiry_month' => '2028-05'])
            ->assertStatus(409)->assertJsonPath('code', 'batch_required');
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1'])
            ->assertStatus(409)->assertJsonPath('code', 'expiry_required');
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2026-10'])
            ->assertStatus(409)->assertJsonPath('code', 'already_expired');
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05', 'mrp_paise' => 3000, 'selling_price_paise' => 3200])
            ->assertStatus(409)->assertJsonPath('code', 'price_above_mrp');
        $this->addStock($product, ['qty' => 0, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05'])
            ->assertStatus(422);

        // Nothing was half-saved by the refused requests.
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, StockAdjustment::count());

        // Stock expiring this month is still sellable until the month ends.
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2026-11'])->assertCreated();
    }

    public function test_loose_goods_are_kept_in_grams_and_need_no_batch(): void
    {
        $sugar = $this->sugar();

        $this->addStock($sugar, ['qty' => 50, 'cost_paise' => 4000])->assertCreated(); // 50 kg at Rs 40
        $this->addStock($sugar, ['qty' => 250, 'unit' => 'base', 'cost_paise' => 4000])->assertCreated(); // and 250 g

        $this->getJson("/api/v1/products/{$sugar->id}")
            ->assertJsonPath('stock_qty', 50250)
            ->assertJsonPath('base_unit', 'G')
            ->assertJsonPath('batches.0.cost_paise', 4000)
            ->assertJsonPath('batches.1.value_paise', 1000);
    }

    public function test_repeated_request_does_not_add_stock_twice_and_key_is_required(): void
    {
        $product = $this->medicine();
        $item = ['qty' => 5, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05'];

        $this->addStock($product, $item, ['Idempotency-Key' => 'same-key'])->assertCreated();
        $this->addStock($product, $item, ['Idempotency-Key' => 'same-key'])->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
        $this->assertSame(50, (int) StockBatch::sum('qty_sellable'));

        $this->postJson("/api/v1/products/{$product->id}/opening-stock", ['items' => [$item]])
            ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
    }

    public function test_stock_cannot_go_below_zero(): void
    {
        $product = $this->medicine();
        $this->addStock($product, ['qty' => 2, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05']);
        $batch = StockBatch::sole();

        $this->postJson("/api/v1/stock/batches/{$batch->id}/adjust",
            ['action' => 'write_off_damage', 'qty' => 21, 'note' => 'Dropped'], $this->headers())
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('available', 20);

        $this->assertSame(20, $batch->refresh()->qty_sellable);
        $this->assertSame(1, InventoryMovement::count());
        $this->assertSame(1, StockAdjustment::count()); // the refused adjustment was rolled back completely
    }

    public function test_adjustments_move_stock_and_leave_a_trail(): void
    {
        $product = $this->medicine();
        $this->addStock($product, ['qty' => 10, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05']);
        $batch = StockBatch::sole();
        $adjust = fn (array $body) => $this->postJson("/api/v1/stock/batches/{$batch->id}/adjust", $body, $this->headers());

        // 15 tablets set aside as not for sale
        $adjust(['action' => 'mark_unsellable', 'qty' => 15, 'note' => 'Water damage'])
            ->assertCreated()->assertJson(['qty_sellable' => 85, 'qty_unsellable' => 15]);
        // 5 of those destroyed
        $adjust(['action' => 'write_off_damage', 'qty' => 5, 'bucket' => 'unsellable', 'note' => 'Destroyed'])
            ->assertCreated()->assertJson(['qty_sellable' => 85, 'qty_unsellable' => 10]);
        // Shelf count finds 80, not 85
        $adjust(['action' => 'count_correction', 'qty' => 80, 'note' => 'Stock count'])
            ->assertCreated()->assertJson(['qty_sellable' => 80, 'qty_unsellable' => 10]);
        // Counting the same again changes nothing and is refused
        $adjust(['action' => 'count_correction', 'qty' => 80, 'note' => 'Stock count'])
            ->assertStatus(409)->assertJsonPath('code', 'no_change');
        // A reason is always required
        $adjust(['action' => 'write_off_damage', 'qty' => 1])->assertStatus(422)->assertJsonValidationErrors('note');
        $adjust(['action' => 'write_off_damage', 'qty' => 0, 'note' => 'Nothing'])->assertStatus(422);

        $this->assertSame(
            ['opening', 'bucket_transfer', 'bucket_transfer', 'damage', 'adjustment'],
            InventoryMovement::orderBy('id')->pluck('type')->all(),
        );
        $this->assertSame([100, -15, 15, -5, -5], InventoryMovement::orderBy('id')->pluck('qty_delta')->all());
        $this->assertSame([], app(StockLedger::class)->findMismatches());
        $this->assertSame(3, AuditLog::where('action', 'stock.adjusted')->count());

        $this->getJson("/api/v1/products/{$product->id}/movements")
            ->assertOk()->assertJsonPath('meta.total', 5)->assertJsonPath('data.0.type', 'adjustment')->assertJsonPath('data.0.note', 'Stock count');
    }

    public function test_ledger_rows_cannot_be_edited_and_stock_only_changes_in_a_transaction(): void
    {
        $product = $this->medicine();
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05']);

        try {
            InventoryMovement::sole()->update(['qty_delta' => 999]);
            $this->fail('A ledger row was edited.');
        } catch (LogicException) {
        }

        // Mass assignment cannot touch the cached quantity either.
        $batch = StockBatch::sole();
        try {
            $batch->fill(['qty_sellable' => 999]);
            $this->fail('The cached quantity was mass-assigned.');
        } catch (MassAssignmentException) {
        }
        $this->assertSame(10, $batch->qty_sellable);

        // RefreshDatabase wraps each test in one transaction; step outside it to prove the guard.
        DB::rollBack();
        try {
            $this->expectException(LogicException::class);
            app(StockLedger::class)->record($batch, 'sellable', 5, 'adjustment', $batch, $this->owner->id);
        } finally {
            DB::beginTransaction();
        }
    }

    public function test_integrity_check_reports_a_quantity_that_does_not_match_the_ledger(): void
    {
        $product = $this->medicine();
        $this->addStock($product, ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05']);
        $batch = StockBatch::sole();

        DB::table('stock_batches')->where('id', $batch->id)->update(['qty_sellable' => 7]); // tampering

        $this->assertSame(
            [['batch_id' => $batch->id, 'bucket' => 'sellable', 'cached' => 7, 'ledger' => 10]],
            app(StockLedger::class)->findMismatches(),
        );
        $this->getJson('/api/v1/stock/summary')->assertJsonPath('ledger_mismatches', 1);
    }

    /** Scenario 9 (stock side): expired stock is counted apart from sellable stock. */
    public function test_expired_stock_is_excluded_from_sellable_stock_and_listed_for_action(): void
    {
        $product = $this->medicine();
        $this->addStock($product, ['qty' => 3, 'cost_paise' => 2340, 'batch_no' => 'OLD', 'expiry_month' => '2026-12']);
        $this->addStock($product, ['qty' => 4, 'cost_paise' => 2340, 'batch_no' => 'NEW', 'expiry_month' => '2028-05']);

        $this->getJson('/api/v1/stock/batches?status=expiring&days=60')->assertJsonCount(1, 'data')->assertJsonPath('data.0.batch_no', 'OLD');
        $this->getJson('/api/v1/stock/batches?status=expiring&days=30')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/summary')->assertJson(['expiring_30' => 0, 'expiring_60' => 1, 'expired_batches' => 0]);

        // 31 Dec is the last selling day; on 1 Jan the batch is expired.
        Carbon::setTestNow(Carbon::parse('2026-12-31 23:00', 'Asia/Kolkata'));
        $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('stock_qty', 70)->assertJsonPath('expired_qty', 0);

        Carbon::setTestNow(Carbon::parse('2027-01-01 00:30', 'Asia/Kolkata'));
        $this->getJson("/api/v1/products/{$product->id}")
            ->assertJsonPath('stock_qty', 40)
            ->assertJsonPath('expired_qty', 30)
            ->assertJsonPath('batches.0.expiry_status', 'expired')
            ->assertJsonPath('nearest_expiry', '2028-05-31');
        $this->getJson('/api/v1/stock/batches?status=expired')->assertJsonCount(1, 'data')->assertJsonPath('data.0.product_name', 'Crocin 500 Tablet');
        $this->getJson('/api/v1/stock/summary')->assertJson(['expired_batches' => 1]);

        // Expired stock cannot be counted back into sale, but can be set aside and written off.
        $old = StockBatch::where('batch_no', 'OLD')->sole();
        $adjust = fn (array $body) => $this->postJson("/api/v1/stock/batches/{$old->id}/adjust", $body, $this->headers());
        $adjust(['action' => 'count_correction', 'qty' => 40, 'note' => 'Found more'])->assertStatus(409)->assertJsonPath('code', 'already_expired');
        $adjust(['action' => 'mark_unsellable', 'qty' => 30, 'note' => 'Expired'])->assertCreated();
        $this->getJson('/api/v1/stock/batches?status=unsellable')->assertJsonCount(1, 'data');
        $adjust(['action' => 'write_off_expiry', 'qty' => 30, 'bucket' => 'unsellable', 'note' => 'Destroyed'])
            ->assertCreated()->assertJson(['qty_sellable' => 0, 'qty_unsellable' => 0]);
    }

    public function test_low_and_out_of_stock_filters(): void
    {
        $low = $this->medicine('Low Tablet');   // minimum 5 strips = 50
        $ok = $this->medicine('Plenty Tablet');
        $out = $this->medicine('Empty Tablet');
        $this->addStock($low, ['qty' => 5, 'cost_paise' => 100, 'batch_no' => 'B', 'expiry_month' => '2028-05']);
        $this->addStock($ok, ['qty' => 6, 'cost_paise' => 100, 'batch_no' => 'B', 'expiry_month' => '2028-05']);

        $this->assertSame(['Low Tablet'], $this->getJson('/api/v1/products?stock=low')->json('data.*.name'));
        $this->assertSame(['Empty Tablet'], $this->getJson('/api/v1/products?stock=out')->json('data.*.name'));
        $this->assertSame(['out', 'low', 'ok'], $this->getJson('/api/v1/products')->json('data.*.stock_status'));
        $this->getJson('/api/v1/stock/summary')
            ->assertJson(['products' => 3, 'low_stock' => 1, 'out_of_stock' => 1, 'stock_value_paise' => 1100, 'ledger_mismatches' => 0]);
        $this->assertNotNull($out);
    }

    public function test_only_permitted_users_change_stock_and_cost_is_hidden_from_cashiers(): void
    {
        $product = $this->medicine();
        $item = ['qty' => 1, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_month' => '2028-05'];
        $this->addStock($product, $item)->assertCreated();
        $batch = StockBatch::sole();

        foreach ([RoleName::Cashier, RoleName::Pharmacist] as $role) {
            $this->actingAs($this->userWithRole($role));
            $this->addStock($product, $item)->assertForbidden();
            $this->postJson("/api/v1/stock/batches/{$batch->id}/adjust",
                ['action' => 'write_off_damage', 'qty' => 1, 'note' => 'Broke'], $this->headers())->assertForbidden();

            $this->getJson("/api/v1/products/{$product->id}")->assertOk()
                ->assertJsonMissingPath('batches.0.cost_paise')->assertJsonMissingPath('batches.0.value_paise');
            $this->getJson('/api/v1/stock/summary')->assertOk()
                ->assertJsonMissingPath('stock_value_paise')->assertJsonMissingPath('ledger_mismatches');
        }

        $this->actingAs($this->userWithRole(RoleName::StoreManager));
        $this->addStock($product, $item)->assertCreated();
        $this->getJson("/api/v1/products/{$product->id}")->assertJsonPath('batches.0.cost_paise', 2340);
        $this->assertSame(20, (int) StockBatch::sum('qty_sellable'));
    }
}
