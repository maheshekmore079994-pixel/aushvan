<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\SaveProduct;
use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Reports\ReportRunner;
use App\Enums\GstMode;
use App\Enums\Permission;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\DrugSchedule;
use App\Models\InventoryMovement;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * Attempts to break the backend: another shop's records, privilege escalation, odd carts,
 * odd payments, odd report filters and the midnight boundary of the shop's business day.
 *
 * A test marked incomplete with "BUG:" asserts the correct behaviour and currently fails.
 */
class RobustnessTest extends TestCase
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

    // ---- Helpers ----------------------------------------------------------------------------

    private function unit(string $code): int
    {
        return Unit::where('code', $code)->value('id');
    }

    private function tax(string $name): int
    {
        return TaxRate::where('name', $name)->value('id');
    }

    /** The owner of a second, unrelated shop. */
    private function otherShopOwner(): User
    {
        $store = Store::create(['name' => 'Other Kirana Stores']);
        $user = User::factory()->create(['store_id' => $store->id, 'name' => 'Other Owner']);
        $user->assignRole(RoleName::Owner->value);

        return $user->refresh();
    }

    /** A strip-of-10 medicine at Rs 30 a strip. */
    private function medicine(string $name, string $schedule = 'NONE', string $expiry = '2028-05-31', int $strips = 10, ?User $as = null): Product
    {
        $as ??= $this->owner;
        $product = app(SaveProduct::class)->handle($as, [
            'type' => 'medical', 'name' => $name, 'hsn_code' => '3004', 'tax_rate_id' => $this->tax('GST 5%'),
            'base_unit_id' => $this->unit('TAB'), 'pack_unit_id' => $this->unit('STRIP'), 'pack_size' => 10,
            'allow_loose_sale' => true, 'mrp_paise' => 3000, 'selling_price_paise' => 3000,
            'medicine' => ['generic_name' => 'Paracetamol', 'drug_schedule_id' => DrugSchedule::where('code', $schedule)->value('id')],
        ]);
        app(AddOpeningStock::class)->handle($as, $product, [['qty' => $strips, 'cost_paise' => 2340, 'batch_no' => 'B1', 'expiry_date' => $expiry]]);

        return $product;
    }

    private function general(string $name, int $pricePaise = 5000, int $stock = 20, ?User $as = null, int $minStock = 0): Product
    {
        $as ??= $this->owner;
        $product = app(SaveProduct::class)->handle($as, [
            'type' => 'general', 'name' => $name, 'tax_rate_id' => $this->tax('GST 5%'), 'min_stock' => $minStock,
            'base_unit_id' => $this->unit('PCS'), 'mrp_paise' => $pricePaise, 'selling_price_paise' => $pricePaise,
        ]);
        if ($stock > 0) {
            app(AddOpeningStock::class)->handle($as, $product, [['qty' => $stock, 'cost_paise' => (int) ($pricePaise * 0.9)]]);
        }

        return $product;
    }

    private function customer(?User $as = null, array $attributes = []): Customer
    {
        return Customer::create($attributes + [
            'ulid' => (string) Str::ulid(), 'store_id' => ($as ?? $this->owner)->store_id, 'name' => 'Ramesh Patil',
            'mobile' => '9800000010', 'search_text' => 'ramesh patil 9800000010',
        ])->refresh();
    }

    private function sell(array|string $lines, array $extra = []): TestResponse
    {
        return $this->postJson('/api/v1/sales', $extra + ['ulid' => (string) Str::ulid(), 'lines' => $lines, 'payments' => []]);
    }

    private function cash(int $paise): array
    {
        return ['payments' => [['method' => 'cash', 'amount_paise' => $paise]]];
    }

    private function pay(Customer $customer, array $body, ?string $key = null): TestResponse
    {
        return $this->postJson("/api/v1/customers/{$customer->id}/payments", $body, ['Idempotency-Key' => $key ?? (string) Str::ulid()]);
    }

    private function report(string $key, array $query = []): array
    {
        return $this->getJson("/api/v1/reports/{$key}?".http_build_query($query))->assertOk()->json();
    }

    private function userPayload(array $overrides = []): array
    {
        return $overrides + [
            'name' => 'Someone', 'username' => 'someone', 'mobile' => null, 'password' => null, 'pin' => null, 'locale' => 'en',
            'is_pharmacist' => false, 'pharmacist_reg_no' => null, 'is_active' => true, 'roles' => ['cashier'],
        ];
    }

    /** A store manager whom the owner has trusted with "users.manage". */
    private function managerWhoManagesUsers(): User
    {
        $manager = $this->userWithRole(RoleName::StoreManager, ['username' => 'manager1']);
        $manager->givePermissionTo(Permission::USERS_MANAGE);

        return $manager->refresh();
    }

    // ---- Another shop's records -----------------------------------------------------------------

    public function test_another_shops_records_cannot_be_opened_or_changed_by_id(): void
    {
        $other = $this->otherShopOwner();
        $theirProduct = $this->general('Their Soap', as: $other);
        $theirBatch = StockBatch::where('product_id', $theirProduct->id)->sole();
        $theirCustomer = $this->customer($other, ['name' => 'Their Customer']);
        $theirSale = $this->actingAs($other)->sell([['product_id' => $theirProduct->id, 'qty' => 1]], $this->cash(5000))->assertCreated()->json('id');

        $this->actingAs($this->owner);
        $key = fn () => ['Idempotency-Key' => (string) Str::ulid()];

        $this->getJson("/api/v1/products/{$theirProduct->id}")->assertNotFound();
        $this->getJson("/api/v1/products/{$theirProduct->id}/movements")->assertNotFound();
        $this->postJson("/api/v1/products/{$theirProduct->id}/barcode")->assertNotFound();
        $this->putJson("/api/v1/products/{$theirProduct->id}", [
            'type' => 'general', 'name' => 'Hijacked', 'tax_rate_id' => $this->tax('GST 5%'), 'base_unit_id' => $this->unit('PCS'),
            'selling_price_paise' => 100,
        ])->assertNotFound();
        $this->postJson("/api/v1/products/{$theirProduct->id}/opening-stock", ['items' => [['qty' => 5, 'cost_paise' => 100]]], $key())->assertNotFound();
        $this->postJson("/api/v1/stock/batches/{$theirBatch->id}/adjust", ['action' => 'write_off_damage', 'qty' => 1, 'note' => 'not mine'], $key())->assertNotFound();
        $this->getJson("/api/v1/sales/{$theirSale}")->assertNotFound();
        $this->getJson("/api/v1/customers/{$theirCustomer->id}")->assertNotFound();
        $this->putJson("/api/v1/customers/{$theirCustomer->id}", ['name' => 'Hijacked'])->assertNotFound();
        $this->pay($theirCustomer, ['amount_paise' => 100, 'method' => 'cash'])->assertNotFound();
        $this->putJson("/api/v1/users/{$other->id}", $this->userPayload(['username' => $other->username, 'roles' => ['cashier'], 'is_active' => false]))->assertNotFound();

        $this->assertSame('Their Soap', $theirProduct->refresh()->name);
        $this->assertSame(5000, $theirProduct->selling_price_paise);
        $this->assertSame(0, $theirProduct->barcodes()->count());
        $this->assertSame(19, $theirBatch->refresh()->qty_sellable);
        $this->assertSame(1, StockBatch::where('store_id', $other->store_id)->count());
        $this->assertSame('Their Customer', $theirCustomer->refresh()->name);
        $this->assertSame(0, $theirCustomer->balance_paise);
        $this->assertSame(1, Payment::count());
        $this->assertTrue($other->refresh()->isOwner());
        $this->assertTrue($other->is_active);
    }

    public function test_lists_searches_labels_and_downloads_leave_out_the_other_shop(): void
    {
        $other = $this->otherShopOwner();
        $theirs = $this->medicine('Their Crocin', expiry: '2026-11-30', as: $other);
        $theirCustomer = $this->customer($other, ['name' => 'Their Customer', 'search_text' => 'their customer 9800000010']);
        $this->actingAs($other)->sell([['product_id' => $theirs->id, 'qty' => 1]], ['customer_id' => $theirCustomer->id])->assertCreated();
        $mine = $this->general('My Soap');

        $this->actingAs($this->owner);

        $this->getJson('/api/v1/products')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.name', 'My Soap');
        $this->getJson('/api/v1/products?search=crocin&inactive=1')->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/products/similar?name=Their+Crocin')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/products/labels?'.http_build_query(['ids' => [$theirs->id, $mine->id]]))
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $mine->id);
        $this->assertStringNotContainsString('Their Crocin', $this->get('/api/v1/products/export')->assertOk()->streamedContent());

        $this->getJson('/api/v1/pos/search?q=crocin')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/sales')->assertJsonPath('meta.total', 0);
        $this->getJson("/api/v1/sales?customer_id={$theirCustomer->id}")->assertJsonPath('meta.total', 0);
        $this->getJson('/api/v1/sales?search=their')->assertJsonPath('meta.total', 0);

        $this->getJson('/api/v1/customers')->assertJsonPath('meta.total', 0)->assertJsonPath('total_owed_paise', 0)->assertJsonPath('customers_owing', 0);
        $this->getJson('/api/v1/customers?search=their&owing=1')->assertJsonPath('meta.total', 0);
        $this->assertNotContains($other->id, array_column($this->getJson('/api/v1/users')->assertOk()->json('data'), 'id'));

        $this->getJson('/api/v1/stock/batches?status=expiring&days=730')->assertJsonCount(0, 'data');
        $this->getJson('/api/v1/stock/summary')
            ->assertJsonPath('products', 1)->assertJsonPath('medical_products', 0)->assertJsonPath('expiring_30', 0)
            ->assertJsonPath('stock_value_paise', 20 * 4500);
    }

    public function test_a_bill_cannot_use_another_shops_product_batch_customer_or_pharmacist(): void
    {
        $other = $this->otherShopOwner();
        $theirSoap = $this->general('Their Soap', as: $other);
        $theirBatch = StockBatch::where('product_id', $theirSoap->id)->sole();
        $theirCustomer = $this->customer($other);
        $theirPharmacist = User::factory()->create(['store_id' => $other->store_id, 'is_pharmacist' => true, 'pharmacist_reg_no' => 'X-1', 'pin' => '4321']);
        $theirPharmacist->assignRole(RoleName::Pharmacist->value);

        $mySoap = $this->general('My Soap');
        $myMox = $this->medicine('Mox 500 Capsule', 'H');
        $rx = ['prescription' => ['prescriber_name' => 'Dr. Deshmukh', 'patient_name' => 'Sunita Jadhav']];

        $this->sell([['product_id' => $theirSoap->id, 'qty' => 1]], $this->cash(5000))->assertStatus(409)->assertJsonPath('code', 'product_unavailable');
        $this->postJson('/api/v1/sales/preview', ['lines' => [['product_id' => $theirSoap->id, 'qty' => 1]]])
            ->assertOk()->assertJsonPath('errors.0.code', 'product_unavailable')->assertJsonPath('errors.0.product', null)->assertJsonCount(0, 'lines');
        $this->sell([['product_id' => $mySoap->id, 'qty' => 1, 'batch_id' => $theirBatch->id]], $this->cash(5000))
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
        $this->sell([['product_id' => $mySoap->id, 'qty' => 1]], ['customer_id' => $theirCustomer->id])->assertNotFound();
        $this->sell([['product_id' => $mySoap->id, 'qty' => 1]], ['customer_id' => $theirCustomer->id] + $this->cash(5000))->assertNotFound();
        $this->sell([['product_id' => $myMox->id, 'qty' => 1]], $this->cash(3000) + $rx + ['pharmacist' => ['user_id' => $theirPharmacist->id, 'pin' => '4321']])
            ->assertStatus(409)->assertJsonPath('code', 'pharmacist_required');
        $this->getJson('/api/v1/pos/pharmacists')->assertJsonCount(0, 'data');

        $this->assertSame(0, Sale::count());
        $this->assertSame(20, $theirBatch->refresh()->qty_sellable);
        $this->assertSame(0, $theirCustomer->refresh()->balance_paise);
        $this->assertSame([], app(StockLedger::class)->findMismatches());
    }

    public function test_reports_and_dashboard_count_only_this_shops_numbers(): void
    {
        $other = $this->otherShopOwner();
        $theirSoap = $this->general('Their Soap', stock: 3, as: $other, minStock: 5);
        $this->medicine('Their Crocin', expiry: '2026-11-30', as: $other);
        $theirCustomer = $this->customer($other);
        $this->actingAs($other);
        $this->sell([['product_id' => $theirSoap->id, 'qty' => 2]], ['customer_id' => $theirCustomer->id] + $this->cash(4000))->assertCreated();
        $this->pay($theirCustomer, ['amount_paise' => 1000, 'method' => 'upi'])->assertCreated();

        $this->actingAs($this->owner);
        $range = ['from' => '2000-01-01', 'to' => '2099-12-31'];

        foreach (array_keys(ReportRunner::REPORTS) as $key) {
            $this->assertSame([], $this->report($key, $range + ['days' => 1])['rows'], "{$key} shows another shop's rows");
        }

        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame(['bills' => 0, 'sales_paise' => 0, 'udhar_paise' => 0, 'profit_paise' => 0], $dashboard['sales']['today']);
        $this->assertSame(['bills' => 0, 'sales_paise' => 0, 'udhar_paise' => 0, 'profit_paise' => 0], $dashboard['sales']['month']);
        $this->assertSame(['cash' => 0, 'upi' => 0, 'card' => 0, 'bank' => 0], $dashboard['received_today']);
        $this->assertSame(0, array_sum(array_column($dashboard['trend'], 'sales_paise')));
        $this->assertSame(['medical_paise' => 0, 'general_paise' => 0], $dashboard['split_30']);
        $this->assertSame(['total_paise' => 0, 'customers' => 0, 'top' => []], $dashboard['udhar']);
        foreach (['top_products', 'low_stock', 'expiring', 'recent_bills'] as $block) {
            $this->assertSame([], $dashboard[$block], "dashboard {$block} shows another shop");
        }

        // And the other shop does see its own.
        $theirs = $this->actingAs($other)->getJson('/api/v1/dashboard')->json();
        $this->assertSame(10000, $theirs['sales']['today']['sales_paise']);
        $this->assertSame(5000, $theirs['udhar']['total_paise']);
        $this->assertSame(['cash' => 4000, 'upi' => 1000, 'card' => 0, 'bank' => 0], $theirs['received_today']);
    }

    public function test_sending_another_shops_cart_id_does_not_hand_over_its_bill(): void
    {
        $other = $this->otherShopOwner();
        $theirSoap = $this->general('Their Soap', as: $other);
        $theirCustomer = $this->customer($other, ['name' => 'Their Customer']);
        $ulid = (string) Str::ulid();
        $this->actingAs($other)->sell([['product_id' => $theirSoap->id, 'qty' => 1]], ['ulid' => $ulid, 'customer_id' => $theirCustomer->id])->assertCreated();

        $mySoap = $this->general('My Soap');
        $response = $this->actingAs($this->cashier)->sell([['product_id' => $mySoap->id, 'qty' => 1]], ['ulid' => $ulid] + $this->cash(5000));

        $leaked = $response->json('shop.name') === 'Other Kirana Stores' || $response->json('customer.name') === 'Their Customer';
        $this->assertFalse($leaked);
        $this->assertSame(1, Sale::where('ulid', $ulid)->count());
        $this->assertSame(20, StockBatch::where('product_id', $mySoap->id)->sole()->qty_sellable);

        $this->assertContains($response->status(), [409, 422]);
    }

    public function test_owner_stock_health_count_ignores_another_shops_batches(): void
    {
        $other = $this->otherShopOwner();
        $theirSoap = $this->general('Their Soap', as: $other);
        DB::table('stock_batches')->where('product_id', $theirSoap->id)->update(['qty_sellable' => 999]); // their ledger is now out of step

        $count = $this->actingAs($this->owner)->getJson('/api/v1/stock/summary')->assertOk()->json('ledger_mismatches');

        $this->assertSame(0, $count);
    }

    // ---- Privilege ------------------------------------------------------------------------------

    public function test_a_manager_who_may_manage_users_cannot_make_themselves_owner(): void
    {
        $manager = $this->managerWhoManagesUsers();

        $response = $this->actingAs($manager)->putJson("/api/v1/users/{$manager->id}", $this->userPayload([
            'name' => $manager->name, 'username' => 'manager1', 'roles' => ['owner'],
        ]));

        $response->assertForbidden();
        $this->assertFalse(User::find($manager->id)->isOwner());
    }

    public function test_a_manager_who_may_manage_users_cannot_create_an_owner_login(): void
    {
        $manager = $this->managerWhoManagesUsers();

        $response = $this->actingAs($manager)->postJson('/api/v1/users', $this->userPayload([
            'name' => 'Back Door', 'username' => 'backdoor', 'password' => 'secret-pass-1', 'roles' => ['owner'],
        ]));

        $response->assertForbidden();
        $this->assertSame(0, User::where('username', 'backdoor')->count());
    }

    public function test_a_manager_who_may_manage_users_cannot_change_or_switch_off_an_owner(): void
    {
        $manager = $this->managerWhoManagesUsers();
        $secondOwner = $this->userWithRole(RoleName::Owner, ['username' => 'owner2']);

        // Take over the owner's login by setting a new password.
        $takeover = $this->actingAs($manager)->putJson("/api/v1/users/{$this->owner->id}", $this->userPayload([
            'name' => $this->owner->name, 'username' => $this->owner->username, 'password' => 'stolen-pass-1', 'roles' => ['owner'],
        ]));
        // Switch off and demote the other owner.
        $switchOff = $this->actingAs($manager)->putJson("/api/v1/users/{$secondOwner->id}", $this->userPayload([
            'name' => $secondOwner->name, 'username' => 'owner2', 'is_active' => false, 'roles' => ['cashier'],
        ]));

        $passwordChanged = ! Hash::check('password', User::find($this->owner->id)->password);
        $second = User::find($secondOwner->id);
        $takeover->assertForbidden();
        $switchOff->assertForbidden();
        $this->assertFalse($passwordChanged);
        $this->assertTrue($second->is_active && $second->isOwner());
    }

    public function test_cashier_is_kept_out_of_reports_roles_users_stock_and_product_changes(): void
    {
        $soap = $this->general('My Soap');
        $batch = StockBatch::sole();
        $roleId = DB::table('roles')->where('name', 'cashier')->value('id');
        $key = fn () => ['Idempotency-Key' => (string) Str::ulid()];
        $this->actingAs($this->cashier);

        $this->getJson('/api/v1/reports')->assertForbidden();
        foreach (array_keys(ReportRunner::REPORTS) as $report) {
            $this->getJson("/api/v1/reports/{$report}")->assertForbidden();
        }
        $this->get('/api/v1/reports/stock_summary/export?format=xlsx')->assertForbidden();
        $this->get('/api/v1/reports/udhar_outstanding/export?format=pdf')->assertForbidden();

        $this->getJson('/api/v1/roles')->assertForbidden();
        $this->postJson('/api/v1/roles', ['name' => 'Boss', 'permissions' => Permission::all()])->assertForbidden();
        $this->putJson("/api/v1/roles/{$roleId}", ['permissions' => Permission::all()])->assertForbidden();
        $this->deleteJson("/api/v1/roles/{$roleId}")->assertForbidden();

        $this->getJson('/api/v1/users')->assertForbidden();
        $this->putJson("/api/v1/users/{$this->cashier->id}", $this->userPayload(['roles' => ['owner']]))->assertForbidden();
        $this->putJson('/api/v1/store', ['name' => 'Mine now'])->assertForbidden();

        $this->postJson("/api/v1/stock/batches/{$batch->id}/adjust", ['action' => 'count_correction', 'qty' => 500, 'note' => 'more please'], $key())->assertForbidden();
        $this->postJson("/api/v1/products/{$soap->id}/opening-stock", ['items' => [['qty' => 5, 'cost_paise' => 100]]], $key())->assertForbidden();
        $this->postJson("/api/v1/products/{$soap->id}/barcode")->assertForbidden();
        $product = ['type' => 'general', 'name' => 'Cheap Soap', 'tax_rate_id' => $this->tax('GST 5%'), 'base_unit_id' => $this->unit('PCS'), 'selling_price_paise' => 100];
        $this->postJson('/api/v1/products', $product)->assertForbidden();
        $this->putJson("/api/v1/products/{$soap->id}", ['name' => 'My Soap'] + $product)->assertForbidden();
        $this->postJson('/api/v1/products', ['type' => 'medical'] + $product)->assertForbidden();

        // Cost and profit stay hidden wherever a cashier can look.
        $this->getJson("/api/v1/products/{$soap->id}")->assertOk()->assertJsonMissingPath('batches.0.cost_paise');
        $this->getJson('/api/v1/stock/summary')->assertOk()->assertJsonMissingPath('stock_value_paise')->assertJsonMissingPath('ledger_mismatches');
        $this->assertStringNotContainsStringIgnoringCase('cost', $this->get('/api/v1/products/export')->assertOk()->streamedContent());

        $this->assertSame(20, $batch->refresh()->qty_sellable);
        $this->assertSame(5000, $soap->refresh()->selling_price_paise);
        $this->assertSame(1, Product::count());
        $this->assertFalse(User::find($this->cashier->id)->isOwner());
        $this->assertSame(0, DB::table('roles')->where('name', 'Boss')->count());
    }

    public function test_a_cashier_cannot_give_the_goods_away_with_a_full_discount(): void
    {
        $soap = $this->general('My Soap');

        $response = $this->sell([['product_id' => $soap->id, 'qty' => 10, 'discount_percent' => 100]]);

        $this->assertContains($response->status(), [403, 409, 422]);
        $this->assertSame(0, Sale::count());
    }

    // ---- Sales edge cases -------------------------------------------------------------------------

    public function test_malformed_carts_are_refused_and_leave_nothing_behind(): void
    {
        $soap = $this->general('My Soap');
        $line = fn (array $over = []) => [$over + ['product_id' => $soap->id, 'qty' => 1]];
        $payment = fn (array $over) => ['payments' => [$over + ['method' => 'cash', 'amount_paise' => 5000]]];

        $bad = [
            'qty zero' => [$line(['qty' => 0]), []],
            'qty negative' => [$line(['qty' => -1]), []],
            'qty huge' => [$line(['qty' => 10000001]), []],
            'qty beyond integer' => [$line(['qty' => '99999999999999999999999']), []],
            'qty fraction' => [$line(['qty' => 1.5]), []],
            'qty words' => [$line(['qty' => 'two']), []],
            'qty missing' => [[['product_id' => $soap->id]], []],
            'product missing' => [[['qty' => 1]], []],
            'product words' => [$line(['product_id' => 'soap']), []],
            'unit unknown' => [$line(['unit' => 'box']), []],
            'batch words' => [$line(['batch_id' => 'B1']), []],
            'discount negative' => [$line(['discount_percent' => -1]), []],
            'discount over 100' => [$line(['discount_percent' => 100.01]), []],
            'discount words' => [$line(['discount_percent' => 'half']), []],
            'line is not an object' => [[5], []],
            'lines is text' => ['soap', []],
            'too many lines' => [array_fill(0, 201, ['product_id' => $soap->id, 'qty' => 1]), []],
            'method unknown' => [$line(), $payment(['method' => 'bitcoin'])],
            'method missing' => [$line(), ['payments' => [['amount_paise' => 5000]]]],
            'payment zero' => [$line(), $payment(['amount_paise' => 0])],
            'payment negative' => [$line(), $payment(['amount_paise' => -5000])],
            'payment fraction' => [$line(), $payment(['amount_paise' => 50.5])],
            'payment huge' => [$line(), $payment(['amount_paise' => 1000000001])],
            'too many payments' => [$line(), ['payments' => array_fill(0, 6, ['method' => 'cash', 'amount_paise' => 100])]],
            'ulid short' => [$line(), ['ulid' => 'ABC']],
            'ulid lower case' => [$line(), ['ulid' => strtolower((string) Str::ulid())]],
            'ulid bad letter' => [$line(), ['ulid' => str_repeat('U', 26)]],
            'ulid missing' => [$line(), ['ulid' => null]],
            'customer words' => [$line(), ['customer_id' => 'ramesh']],
            'notes too long' => [$line(), ['notes' => str_repeat('x', 301)]],
        ];

        $wrong = [];
        foreach ($bad as $name => [$lines, $extra]) {
            $status = $this->sell($lines, $extra + $this->cash(5000))->status();
            if ($status !== 422) {
                $wrong[$name] = $status;
            }
        }

        $this->assertSame([], $wrong, 'These malformed carts were not answered with 422');
        $this->assertSame(0, Sale::count() + Payment::count());
        $this->assertSame(20, StockBatch::sole()->qty_sellable);
        $this->assertSame(1, InventoryMovement::count());

        // Negative and reversed payments that add up to the total are still refused.
        $this->sell($line(), ['payments' => [['method' => 'cash', 'amount_paise' => 6000], ['method' => 'upi', 'amount_paise' => -1000]]])->assertStatus(422);
        $this->sell($line(), $this->cash(5000))->assertCreated()->assertJsonPath('invoice_no', '26-27/000001');
    }

    public function test_a_cart_sent_as_an_object_instead_of_a_list_does_not_crash(): void
    {
        $soap = $this->general('My Soap');

        $response = $this->sell(['first' => ['product_id' => $soap->id, 'qty' => 1]], $this->cash(5000));

        $this->assertContains($response->status(), [201, 422]);
        $this->assertSame([], app(StockLedger::class)->findMismatches());
    }

    public function test_oddly_written_discounts_never_crash_the_server(): void
    {
        $soap = $this->general('My Soap', stock: 50);
        $this->actingAs($this->owner);

        $crashed = [];
        foreach (['5.', '.5', '+5', '1e1', '1E-3', '0x10', '05', '5,5', '99.999999999', '1e-300'] as $discount) {
            foreach (['/api/v1/sales/preview', '/api/v1/sales'] as $url) {
                $status = $this->postJson($url, ['ulid' => (string) Str::ulid(), 'lines' => [['product_id' => $soap->id, 'qty' => 1, 'discount_percent' => $discount]], 'payments' => []])->status();
                if ($status >= 500) {
                    $crashed[] = "{$discount} -> {$status} on {$url}";
                }
            }
        }

        $this->assertSame([], $crashed, 'These discounts crashed the pricer');
        $this->assertSame([], app(StockLedger::class)->findMismatches());
        foreach (Sale::all() as $sale) {
            $this->assertSame($sale->total_paise, $sale->paid_paise + $sale->credit_paise);
            $this->assertGreaterThanOrEqual(0, $sale->discount_paise);
            $this->assertLessThanOrEqual($sale->gross_paise, $sale->discount_paise);
        }
    }

    public function test_two_lines_of_one_product_cannot_together_take_more_than_the_stock(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet', strips: 3); // 30 tablets
        $strips = fn (int $qty) => ['product_id' => $crocin->id, 'qty' => $qty];
        $tablets = fn (int $qty) => ['product_id' => $crocin->id, 'qty' => $qty, 'unit' => 'base'];

        $this->sell([$strips(2), $strips(2)], $this->cash(12000))
            ->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')
            ->assertJsonPath('line_errors.0.index', 1)->assertJsonPath('line_errors.0.available', 1);
        $this->sell([$strips(2), $tablets(11)], $this->cash(9300))->assertStatus(409)->assertJsonPath('line_errors.0.index', 1);
        $this->sell([$tablets(25), $strips(1)], $this->cash(10500))->assertStatus(409)->assertJsonPath('line_errors.0.available', 0);
        $this->assertSame(0, Sale::count());
        $this->assertSame(30, StockBatch::sole()->qty_sellable);

        // Exactly everything, across three lines, is fine and empties the batch.
        $this->sell([$strips(1), $tablets(10), $strips(1)], $this->cash(9000))->assertCreated()->assertJsonPath('totals.total_paise', 9000);
        $this->assertSame(0, StockBatch::sole()->qty_sellable);
        $this->assertSame([], app(StockLedger::class)->findMismatches());

        $this->sell([$tablets(1)], $this->cash(300))->assertStatus(409)->assertJsonPath('code', 'insufficient_stock');
    }

    public function test_stock_sells_until_the_end_of_its_expiry_day_in_shop_time_and_not_a_minute_later(): void
    {
        $crocin = $this->medicine('Crocin 500 Tablet', expiry: '2026-11-30');
        $batch = StockBatch::sole();
        $line = [['product_id' => $crocin->id, 'qty' => 1]];

        Carbon::setTestNow(Carbon::parse('2026-11-30 23:59:30', 'Asia/Kolkata'));
        $this->sell($line, $this->cash(3000))->assertCreated()->assertJsonPath('sale_date', '2026-11-30');

        // Ten minutes past midnight in the shop; the server clock (UTC) still says 30 November.
        Carbon::setTestNow(Carbon::parse('2026-12-01 00:10:00', 'Asia/Kolkata'));
        $this->assertSame('2026-11-30', now()->toDateString());

        $this->sell($line, $this->cash(3000))->assertStatus(409)->assertJsonPath('code', 'insufficient_stock')->assertJsonPath('line_errors.0.available', 0);
        $this->sell([$line[0] + ['batch_id' => $batch->id]], $this->cash(3000))->assertStatus(409)->assertJsonPath('code', 'batch_expired');
        $this->postJson('/api/v1/sales/preview', ['lines' => $line])->assertOk()->assertJsonPath('errors.0.code', 'insufficient_stock');
        $this->getJson('/api/v1/pos/search?q=crocin')->assertJsonPath('data.0.stock_qty', 0)->assertJsonCount(0, 'data.0.batches');

        $this->assertSame(1, Sale::count());
        $this->assertSame(90, $batch->refresh()->qty_sellable);
    }

    public function test_a_cart_id_sent_again_with_different_goods_never_makes_or_changes_a_bill(): void
    {
        $soap = $this->general('My Soap');
        $rice = $this->general('Rice 1kg', 9000);
        $ulid = (string) Str::ulid();

        $first = $this->sell([['product_id' => $soap->id, 'qty' => 1]], ['ulid' => $ulid] + $this->cash(5000))->assertCreated();
        $again = $this->sell([['product_id' => $rice->id, 'qty' => 5]], ['ulid' => $ulid] + $this->cash(45000));
        // Another counter in the same shop reusing the id gets the same answer.
        $other = $this->actingAs($this->owner)->sell([['product_id' => $rice->id, 'qty' => 1]], ['ulid' => $ulid] + $this->cash(9000));

        foreach ([$again, $other] as $response) {
            $this->assertContains($response->status(), [200, 409, 422]);
            if ($response->status() === 200) {
                $response->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('invoice_no', $first->json('invoice_no'))
                    ->assertJsonPath('items.0.product_name', 'My Soap')->assertJsonPath('totals.total_paise', 5000);
            }
        }
        $this->assertSame(1, Sale::count());
        $this->assertSame(5000, (int) Payment::sum('amount_paise'));
        $this->assertSame(20, StockBatch::where('product_id', $rice->id)->sole()->qty_sellable);
    }

    public function test_a_prescription_dated_today_is_accepted_in_the_early_hours_of_the_shops_day(): void
    {
        // 02:00 in the shop on 11 November; the server clock (UTC) is still on 10 November.
        Carbon::setTestNow(Carbon::parse('2026-11-11 02:00', 'Asia/Kolkata'));
        $mox = $this->medicine('Mox 500 Capsule', 'H');
        $pharmacist = $this->userWithRole(RoleName::Pharmacist, ['is_pharmacist' => true, 'pharmacist_reg_no' => 'MSPC-1']);
        $rx = fn (string $date) => ['prescription' => ['prescriber_name' => 'Dr. Deshmukh', 'patient_name' => 'Sunita Jadhav', 'prescription_date' => $date]];
        $this->actingAs($pharmacist);

        $this->sell([['product_id' => $mox->id, 'qty' => 1]], $this->cash(3000) + $rx('2026-11-12'))->assertStatus(422); // tomorrow
        $response = $this->sell([['product_id' => $mox->id, 'qty' => 1]], $this->cash(3000) + $rx('2026-11-11'));

        $response->assertCreated()->assertJsonPath('sale_date', '2026-11-11');
    }

    public function test_a_switched_off_product_or_customer_cannot_be_billed(): void
    {
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $line = [['product_id' => $soap->id, 'qty' => 1]];

        $ramesh->update(['is_active' => false]);
        $this->assertContains($this->sell($line, ['customer_id' => $ramesh->id])->status(), [404, 409, 422]);

        $soap->update(['is_active' => false]);
        $this->sell($line, $this->cash(5000))->assertStatus(409)->assertJsonPath('code', 'product_unavailable');
        $this->getJson('/api/v1/pos/search?q=soap')->assertJsonCount(0, 'data');

        $this->assertSame(0, Sale::count());
        $this->assertSame(0, $ramesh->refresh()->balance_paise);
    }

    // ---- Udhar ----------------------------------------------------------------------------------

    public function test_malformed_udhar_payments_are_refused_and_post_nothing(): void
    {
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $this->sell([['product_id' => $soap->id, 'qty' => 2]], ['customer_id' => $ramesh->id])->assertCreated(); // owes Rs 100

        $bad = [
            ['amount_paise' => 0, 'method' => 'cash'],
            ['amount_paise' => -5000, 'method' => 'cash'],
            ['amount_paise' => 50.5, 'method' => 'cash'],
            ['amount_paise' => 'all', 'method' => 'cash'],
            ['amount_paise' => 1000000001, 'method' => 'cash'],
            ['amount_paise' => 5000, 'method' => 'udhar'],
            ['amount_paise' => 5000],
            ['method' => 'cash'],
            ['amount_paise' => 5000, 'method' => 'cash', 'reference_no' => str_repeat('9', 51)],
        ];
        foreach ($bad as $body) {
            $this->pay($ramesh, $body)->assertStatus(422);
        }
        $this->postJson("/api/v1/customers/{$ramesh->id}/payments", ['amount_paise' => 5000, 'method' => 'cash'])
            ->assertStatus(400)->assertJsonPath('code', 'idempotency_key_missing');
        $this->pay($ramesh, ['amount_paise' => 5000, 'method' => 'cash'], str_repeat('k', 65))->assertStatus(400);
        $this->pay((new Customer)->forceFill(['id' => 999999]), ['amount_paise' => 5000, 'method' => 'cash'])->assertNotFound();

        $this->assertSame(10000, $ramesh->refresh()->balance_paise);
        $this->assertSame(0, Payment::count());
        $this->assertSame(1, CustomerLedgerEntry::count());

        // A key that failed validation can be used again once the amount is corrected.
        $this->pay($ramesh, ['amount_paise' => 0, 'method' => 'cash'], 'retry-1')->assertStatus(422);
        $this->pay($ramesh, ['amount_paise' => 4000, 'method' => 'cash'], 'retry-1')->assertCreated()->assertJsonPath('balance_paise', 6000);
    }

    public function test_a_repeated_payment_request_is_taken_once_even_when_sent_many_times(): void
    {
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $this->sell([['product_id' => $soap->id, 'qty' => 10]], ['customer_id' => $ramesh->id])->assertCreated(); // owes Rs 500

        $first = $this->pay($ramesh, ['amount_paise' => 10000, 'method' => 'cash'], 'same-key')->assertCreated()->assertJsonPath('balance_paise', 40000);
        for ($i = 0; $i < 3; $i++) {
            $again = $this->pay($ramesh, ['amount_paise' => 10000, 'method' => 'cash'], 'same-key')
                ->assertCreated()->assertHeader('Idempotency-Replayed', 'true');
            $this->assertSame($first->json(), $again->json());
        }
        // Another user who happens to use the same key is a different request.
        $this->actingAs($this->owner);
        $this->pay($ramesh, ['amount_paise' => 5000, 'method' => 'upi'], 'same-key')->assertCreated()->assertJsonPath('balance_paise', 35000);

        $this->assertSame(35000, $ramesh->refresh()->balance_paise);
        $this->assertSame(2, Payment::count());
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
    }

    public function test_a_payment_key_reused_for_a_different_customer_is_not_answered_with_the_first_customers_result(): void
    {
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $sunita = $this->customer(null, ['name' => 'Sunita Jadhav', 'mobile' => '9700000022']);
        $this->sell([['product_id' => $soap->id, 'qty' => 4]], ['customer_id' => $ramesh->id])->assertCreated();
        $this->sell([['product_id' => $soap->id, 'qty' => 4]], ['customer_id' => $sunita->id])->assertCreated();

        $this->pay($ramesh, ['amount_paise' => 10000, 'method' => 'cash'], 'key-1')->assertCreated()->assertJsonPath('balance_paise', 10000);
        $second = $this->pay($sunita, ['amount_paise' => 15000, 'method' => 'cash'], 'key-1');

        if ($second->status() === 201) {
            $second->assertJsonPath('id', $sunita->id)->assertJsonPath('balance_paise', 5000);
        } else {
            $this->assertContains($second->status(), [409, 422]);
            $this->assertSame(20000, $sunita->refresh()->balance_paise);
        }
    }

    public function test_balance_always_equals_the_statement_after_a_mix_of_bills_payments_and_an_advance(): void
    {
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $line = fn (int $qty) => [['product_id' => $soap->id, 'qty' => $qty]];
        $with = ['customer_id' => $ramesh->id];

        $this->sell($line(10), $with)->assertCreated()->assertJsonPath('customer.balance_paise', 50000);
        $this->pay($ramesh, ['amount_paise' => 20000, 'method' => 'cash'])->assertJsonPath('balance_paise', 30000);
        $this->sell($line(4), $with + $this->cash(5000))->assertCreated()->assertJsonPath('customer.balance_paise', 45000);
        $this->sell($line(1), $with + $this->cash(5000))->assertCreated()->assertJsonPath('customer.balance_paise', 45000); // paid in full
        $this->pay($ramesh, ['amount_paise' => 60000, 'method' => 'upi'])->assertJsonPath('balance_paise', -15000);      // advance
        $this->sell($line(2), $with)->assertCreated()->assertJsonPath('customer.balance_paise', -5000);                    // against the advance
        $this->sell($line(9), $with)->assertStatus(409);                                                                   // only 3 left

        $ramesh->refresh();
        $this->assertSame(-5000, $ramesh->balance_paise);

        $statement = array_reverse($this->getJson("/api/v1/customers/{$ramesh->id}")->assertOk()->assertJsonPath('balance_paise', -5000)->json('entries'));
        $running = 0;
        foreach ($statement as $entry) {
            $running += $entry['debit_paise'] - $entry['credit_paise'];
            $this->assertSame($running, $entry['balance_after_paise']);
            $this->assertTrue(($entry['debit_paise'] > 0) xor ($entry['credit_paise'] > 0));
        }
        $this->assertSame(-5000, $running);
        $this->assertCount(6, $statement);
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
        $this->assertSame([], app(StockLedger::class)->findMismatches());

        // Money in = bill payments + udhar payments; goods out = bills.
        $this->assertSame(90000, (int) Payment::sum('amount_paise'));
        $this->assertSame(85000, (int) Sale::sum('total_paise'));
        $this->assertSame((int) Sale::sum('total_paise') - (int) Payment::sum('amount_paise'), $ramesh->balance_paise);

        // An advance is not "owed": it stays out of the owing totals but appears in the udhar report.
        $this->getJson('/api/v1/customers?owing=1')->assertJsonPath('total_owed_paise', 0)->assertJsonPath('customers_owing', 0)->assertJsonPath('meta.total', 0);
        $this->actingAs($this->owner);
        $this->assertSame(-5000, $this->report('udhar_outstanding')['totals']['balance']);
        $this->getJson('/api/v1/dashboard')->assertJsonPath('udhar.total_paise', 0);
    }

    // ---- Reports --------------------------------------------------------------------------------

    public function test_bad_report_filters_are_refused_and_absurd_ranges_do_not_crash(): void
    {
        $this->general('My Soap');
        $this->actingAs($this->owner);

        $bad = [
            'sales_by_day?from=2026-11-12&to=2026-11-11', 'bills?from=11-11-2026', 'bills?to=2026-13-01', 'bills?from=2026-02-30',
            'bills?from=yesterday', 'bills?from[]=2026-11-01', 'expiry?days=0', 'expiry?days=-1', 'expiry?days=731', 'slow_moving?days=abc',
            'slow_moving?days=1.5', 'stock_summary?type=grocery', 'sales_by_item?type[]=medical',
        ];
        foreach ($bad as $url) {
            $this->assertSame(422, $this->getJson("/api/v1/reports/{$url}")->status(), $url);
        }
        $this->getJson('/api/v1/reports/passwords')->assertNotFound();
        $this->getJson('/api/v1/reports/passwords/export?format=csv')->assertNotFound();
        $this->getJson('/api/v1/reports/bills/export')->assertStatus(422);
        $this->getJson('/api/v1/reports/bills/export?format=exe')->assertStatus(422);
        $this->getJson('/api/v1/reports/bills/export?format=csv&lang=xx')->assertStatus(422);
        $this->getJson('/api/v1/reports/bills/export?format=csv&from=2026-12-01&to=2026-11-01')->assertStatus(422);
        $this->assertSame(0, DB::table('audit_logs')->where('action', 'reports.exported')->count());

        foreach (array_keys(ReportRunner::REPORTS) as $key) {
            $this->report($key, ['from' => '0001-01-01', 'to' => '9999-12-31', 'days' => 730]);
            $this->report($key, ['from' => '2099-01-01']);                 // starts after today: empty, not an error
            $this->assertSame([], $this->report($key, ['from' => '1970-01-01', 'to' => '1970-01-01', 'type' => 'medical', 'days' => 1])['rows']);
        }
        $this->get('/api/v1/reports/stock_movements/export?format=pdf&from=0001-01-01&to=9999-12-31')->assertOk();
    }

    public function test_a_report_can_be_asked_for_up_to_a_date_without_giving_a_start(): void
    {
        $this->general('My Soap');
        $response = $this->actingAs($this->owner)->getJson('/api/v1/reports/bills?to=2026-11-11');

        $response->assertOk()->assertJsonPath('filters.to', '2026-11-11');
    }

    public function test_a_bill_made_at_midnight_shop_time_lands_on_the_new_business_day_everywhere(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-11-01 10:00', 'Asia/Kolkata'));
        $soap = $this->general('My Soap');
        $ramesh = $this->customer();
        $line = [['product_id' => $soap->id, 'qty' => 1]];

        Carbon::setTestNow(Carbon::parse('2026-11-10 23:59:59', 'Asia/Kolkata'));
        $this->sell($line, $this->cash(5000))->assertCreated()->assertJsonPath('sale_date', '2026-11-10');

        // Midnight exactly: 18:30 the evening before on the server's UTC clock.
        Carbon::setTestNow(Carbon::parse('2026-11-11 00:00:00', 'Asia/Kolkata'));
        $this->assertSame('2026-11-10', now()->toDateString());
        $this->sell([['product_id' => $soap->id, 'qty' => 3]], ['customer_id' => $ramesh->id] + $this->cash(5000))->assertCreated()->assertJsonPath('sale_date', '2026-11-11');
        $this->pay($ramesh, ['amount_paise' => 4000, 'method' => 'upi'])->assertCreated();

        $this->assertSame(['2026-11-10', '2026-11-11', '2026-11-11'], Payment::orderBy('id')->get()->map(fn (Payment $p) => $p->business_date->toDateString())->all());
        $this->assertSame(['2026-11-11'], CustomerLedgerEntry::all()->map(fn (CustomerLedgerEntry $e) => $e->entry_date->toDateString())->unique()->values()->all());

        $this->actingAs($this->owner);
        $day = fn (string $date) => ['from' => $date, 'to' => $date];

        $tenth = $this->report('sales_by_day', $day('2026-11-10'))['rows'];
        $this->assertCount(1, $tenth);
        $this->assertSame(['2026-11-10', 1, 5000, 5000, 0, 0], [$tenth[0]['date'], $tenth[0]['bills'], $tenth[0]['total'], $tenth[0]['cash'], $tenth[0]['udhar'], $tenth[0]['udhar_received']]);

        $eleventh = $this->report('sales_by_day', $day('2026-11-11'))['rows'];
        $this->assertCount(1, $eleventh);
        $this->assertSame(['2026-11-11', 1, 15000, 5000, 10000, 4000], [$eleventh[0]['date'], $eleventh[0]['bills'], $eleventh[0]['total'], $eleventh[0]['cash'], $eleventh[0]['udhar'], $eleventh[0]['udhar_received']]);

        $this->assertCount(1, $this->report('bills', $day('2026-11-10'))['rows']);
        $this->assertCount(1, $this->report('bills', $day('2026-11-11'))['rows']);
        $this->assertSame(1, $this->report('sales_by_item', $day('2026-11-10'))['rows'][0]['qty']);
        $this->assertSame(3, $this->report('sales_by_item', $day('2026-11-11'))['rows'][0]['qty']);
        $this->assertSame(5000, $this->report('gst_summary', $day('2026-11-10'))['totals']['amount']);
        $this->assertSame(15000, $this->report('gst_summary', $day('2026-11-11'))['totals']['amount']);

        $changes = fn (string $date) => array_column($this->report('stock_movements', $day($date))['rows'], 'change');
        $this->assertSame([20], $changes('2026-11-01'));
        $this->assertSame([-1], $changes('2026-11-10'));
        $this->assertSame([-3], $changes('2026-11-11'));
        $this->assertSame([], $changes('2026-11-09'));
        $this->assertSame([], $changes('2026-11-12'));

        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertSame('2026-11-11', $dashboard['today']);
        $this->assertSame(15000, $dashboard['sales']['today']['sales_paise']);
        $this->assertSame(5000, $dashboard['sales']['yesterday']['sales_paise']);
        $this->assertSame(['cash' => 5000, 'upi' => 4000, 'card' => 0, 'bank' => 0], $dashboard['received_today']);
        $this->assertSame(['2026-11-11', 15000], [end($dashboard['trend'])['date'], end($dashboard['trend'])['sales_paise']]);
        $this->getJson('/api/v1/sales?from=2026-11-11&to=2026-11-11')->assertJsonPath('meta.total', 1);
    }

    public function test_every_report_total_is_the_sum_of_its_rows_and_money_is_fully_accounted_for(): void
    {
        $soap = $this->general('My Soap', 4995);
        $crocin = $this->medicine('Crocin 500 Tablet');
        $ramesh = $this->customer();
        $this->actingAs($this->owner); // the bill below carries a line discount
        Carbon::setTestNow(Carbon::parse('2026-11-12 09:00', 'Asia/Kolkata'));
        $this->sell([['product_id' => $soap->id, 'qty' => 3], ['product_id' => $crocin->id, 'qty' => 7, 'unit' => 'base', 'discount_percent' => '12.5']], [
            'customer_id' => $ramesh->id, 'payments' => [['method' => 'card', 'amount_paise' => 3000], ['method' => 'bank', 'amount_paise' => 2000]],
        ])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-13 21:00', 'Asia/Kolkata'));
        $total = $this->postJson('/api/v1/sales/preview', ['lines' => [['product_id' => $soap->id, 'qty' => 1]]])->json('totals.total_paise');
        $this->sell([['product_id' => $soap->id, 'qty' => 1]], $this->cash($total))->assertCreated();
        $this->pay($ramesh, ['amount_paise' => 2500, 'method' => 'upi'])->assertCreated();

        $this->actingAs($this->owner);
        foreach (array_keys(ReportRunner::REPORTS) as $key) {
            $result = $this->report($key, ['from' => '2026-11-01', 'to' => '2026-11-30', 'days' => 1]);
            $this->assertFalse($result['truncated']);
            foreach ($result['totals'] as $column => $value) {
                $this->assertSame(array_sum(array_column($result['rows'], $column)), $value, "{$key}.{$column}");
            }
        }

        $byDay = $this->report('sales_by_day', ['from' => '2026-11-01', 'to' => '2026-11-30']);
        foreach ($byDay['rows'] as $row) {
            // Udhar collected later is shown in its own column and is not part of the day's bills.
            $this->assertSame($row['total'], $row['cash'] + $row['upi'] + $row['card'] + $row['bank'] + $row['udhar'], $row['date']);
        }
        $this->assertSame([0, 2500], array_column($byDay['rows'], 'udhar_received'));
        $totals = $byDay['totals'];
        $this->assertSame((int) Sale::sum('total_paise'), $totals['total']);
        $this->assertSame((int) Payment::sum('amount_paise'), $totals['cash'] + $totals['upi'] + $totals['card'] + $totals['bank'] + $totals['udhar_received']);
        $this->assertSame($ramesh->refresh()->balance_paise, $totals['udhar'] - $totals['udhar_received']);
        $this->assertSame($totals['total'], $this->report('bills', ['from' => '2026-11-01', 'to' => '2026-11-30'])['totals']['total']);

        // Line amounts, tax and round-off add back up to the bills.
        $gst = $this->report('gst_summary', ['from' => '2026-11-01', 'to' => '2026-11-30'])['totals'];
        $this->assertSame($gst['amount'], $gst['taxable'] + $gst['cgst'] + $gst['sgst'] + $gst['igst'] + $gst['cess']);
        $this->assertSame($totals['total'], $gst['amount'] + (int) Sale::sum('round_off_paise'));
        $this->assertSame($gst['amount'], $this->report('sales_by_item', ['from' => '2026-11-01', 'to' => '2026-11-30'])['totals']['amount']);
    }

    // ---- Sign-in --------------------------------------------------------------------------------

    public function test_logout_really_ends_the_session(): void
    {
        $this->app['auth']->forgetGuards();
        $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);

        // Each request below starts with no remembered user, like a real browser request:
        // only the session cookie it carries can sign it in.
        $cookie = config('session.cookie');
        $fresh = function (?string $session) use ($cookie) {
            $this->app['auth']->forgetGuards();
            $this->app['session']->driver()->flush(); // the test kernel would otherwise carry session data over
            $this->defaultCookies = [];

            return $session === null ? $this : $this->withCredentials()->withCookie($cookie, $session);
        };

        $login = $fresh(null)->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'password'])->assertOk();
        $session = $login->getCookie($cookie)->getValue();

        $fresh(null)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $fresh($session)->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('username', 'ravi'); // the cookie is what signs in
        $fresh($session)->getJson('/api/v1/sales')->assertOk();

        $fresh($session)->postJson('/api/v1/auth/logout')->assertNoContent();

        // The old cookie (kept by a browser tab, or copied) is worth nothing now.
        $fresh($session)->getJson('/api/v1/auth/me')->assertUnauthorized();
        $fresh($session)->getJson('/api/v1/sales')->assertUnauthorized();
        $fresh($session)->getJson('/api/v1/dashboard')->assertUnauthorized();
        $fresh($session)->postJson('/api/v1/auth/logout')->assertUnauthorized();
    }

    public function test_a_user_who_is_switched_off_or_loses_a_role_mid_session_is_stopped_at_the_next_request(): void
    {
        $soap = $this->general('My Soap');
        $line = [['product_id' => $soap->id, 'qty' => 1]];
        $this->actingAs($this->cashier)->sell($line, $this->cash(5000))->assertCreated();

        // The owner takes the cashier role away: billing stops, the login remains.
        $this->cashier->syncRoles([]);
        $this->sell($line, $this->cash(5000))->assertForbidden();
        $this->getJson('/api/v1/auth/me')->assertOk()->assertJsonPath('permissions', []);

        $this->cashier->syncRoles(['cashier']);
        User::whereKey($this->cashier->id)->update(['is_active' => false]); // changed by someone else, elsewhere
        $this->cashier->refresh();
        $this->sell($line, $this->cash(5000))->assertUnauthorized()->assertJsonPath('code', 'account_disabled');
        $this->postJson('/api/v1/sales/preview', ['lines' => $line])->assertUnauthorized();

        $this->assertSame(1, Sale::count());
        $this->assertSame(19, StockBatch::sole()->qty_sellable);
    }

    public function test_login_lockout_is_per_username_and_ends_after_a_minute(): void
    {
        $this->app['auth']->forgetGuards();
        $this->userWithRole(RoleName::Cashier, ['username' => 'ravi']);
        $this->userWithRole(RoleName::Cashier, ['username' => 'sunita']);

        foreach (['ravi', 'RAVI', ' Ravi ', 'ravi', 'rAVI'] as $spelling) { // spelling tricks do not earn more tries
            $this->postJson('/api/v1/auth/login', ['username' => $spelling, 'password' => 'wrong-password'])->assertStatus(422);
        }
        $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'password'])->assertStatus(429)->assertJsonPath('code', 'too_many_attempts');
        $this->getJson('/api/v1/auth/me')->assertUnauthorized();

        // Someone else at the same counter is not locked out.
        $this->postJson('/api/v1/auth/login', ['username' => 'sunita', 'password' => 'password'])->assertOk();
        $this->postJson('/api/v1/auth/logout')->assertNoContent();

        $this->travel(61)->seconds();
        $this->postJson('/api/v1/auth/login', ['username' => 'ravi', 'password' => 'password'])->assertOk()->assertJsonPath('username', 'ravi');

        // Odd input is a validation error, never a crash.
        $this->postJson('/api/v1/auth/logout')->assertNoContent();
        $this->postJson('/api/v1/auth/login', ['username' => ['ravi'], 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['username' => str_repeat('a', 51), 'password' => 'password'])->assertStatus(422);
        $this->postJson('/api/v1/auth/login', ['username' => 'ravi'])->assertStatus(422);
    }
}
