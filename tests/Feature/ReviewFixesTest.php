<?php

namespace Tests\Feature;

use App\Actions\CompleteSale;
use App\Domain\Reports\ReportExporter;
use App\Domain\Shared\Rate;
use App\Enums\Permission;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Tests\TestCase;

/** One test per defect found in the code review of 2026-11-11, so none of them can come back unnoticed. */
class ReviewFixesTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $pharmacist;

    private User $manager;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoDataSeeder::class);
        foreach (['owner', 'pharmacist', 'manager', 'cashier'] as $name) {
            $this->{$name} = User::where('username', $name)->sole();
        }
    }

    public function test_hidden_profit_and_cost_are_not_sent_at_all(): void
    {
        // The pharmacist may read reports but holds neither "see profit" nor "see cost".
        $this->actingAs($this->pharmacist);

        foreach (['bills', 'sales_by_day', 'sales_by_item'] as $report) {
            $rows = $this->getJson("/api/v1/reports/{$report}?from=2000-01-01&to=2099-12-31")->assertOk()->json('rows');
            $this->assertNotEmpty($rows);
            $this->assertArrayNotHasKey('profit', $rows[0], $report);
        }
        foreach (['stock_summary', 'expiry?days=90', 'slow_moving?days=1'] as $report) {
            $rows = $this->getJson("/api/v1/reports/{$report}")->assertOk()->json('rows');
            $this->assertNotEmpty($rows);
            $this->assertArrayNotHasKey('value', $rows[0], $report);
        }

        $dashboard = $this->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertArrayNotHasKey('profit_paise', $dashboard['mine']);
        $this->assertStringNotContainsString('profit', json_encode($dashboard));

        // The owner still gets them.
        $this->assertArrayHasKey('profit', $this->actingAs($this->owner)->getJson('/api/v1/reports/bills?from=2000-01-01&to=2099-12-31')->json('rows.0'));
    }

    public function test_a_user_manager_cannot_climb_above_their_own_permissions(): void
    {
        $this->manager->givePermissionTo(Permission::USERS_MANAGE);
        $this->actingAs($this->manager->refresh());
        $new = fn (array $roles) => [
            'name' => 'New Person', 'username' => 'new'.Str::lower(Str::random(6)), 'password' => 'some-pass-123', 'locale' => 'en',
            'is_pharmacist' => false, 'is_active' => true, 'roles' => $roles,
        ];
        $self = fn (array $roles) => ['name' => $this->manager->name, 'username' => 'manager', 'locale' => 'en', 'is_pharmacist' => false, 'is_active' => true, 'roles' => $roles];

        $this->postJson('/api/v1/users', $new(['owner']))->assertForbidden()->assertJsonPath('code', 'owner_only');
        $this->putJson("/api/v1/users/{$this->manager->id}", $self(['store_manager', 'owner']))->assertForbidden();
        $this->putJson("/api/v1/users/{$this->owner->id}", ['name' => 'Taken', 'username' => 'owner', 'password' => 'taken-over-123', 'locale' => 'en', 'is_pharmacist' => false, 'is_active' => true, 'roles' => ['owner']])
            ->assertForbidden()->assertJsonPath('code', 'owner_only');
        // The cashier role can make bills; the manager cannot, so the manager may not hand it out or take it.
        $this->postJson('/api/v1/users', $new(['cashier']))->assertForbidden()->assertJsonPath('code', 'role_above_own');
        $this->putJson("/api/v1/users/{$this->manager->id}", $self(['store_manager', 'cashier']))->assertForbidden();

        $this->assertFalse($this->manager->refresh()->isOwner());
        $this->assertSame(config('shop.demo.owner_name'), $this->owner->refresh()->name); // the demo owner's name comes from DEMO_OWNER_NAME

        // A role within their own permissions is fine, and so is editing someone without changing roles.
        $this->postJson('/api/v1/users', $new(['store_manager']))->assertCreated();
        $this->putJson("/api/v1/users/{$this->cashier->id}", ['name' => 'Ravi S.', 'username' => 'cashier', 'locale' => 'mr', 'is_pharmacist' => false, 'is_active' => true, 'roles' => ['cashier']])
            ->assertOk()->assertJsonPath('name', 'Ravi S.');

        // An owner is not restricted.
        $this->actingAs($this->owner)->postJson('/api/v1/users', $new(['owner']))->assertCreated();
    }

    public function test_a_discount_needs_the_discount_permission(): void
    {
        $line = ['product_id' => Product::where('name', 'Parle-G Biscuit 50g')->value('id'), 'qty' => 1, 'discount_percent' => 100];
        $sale = fn () => ['ulid' => (string) Str::ulid(), 'lines' => [$line], 'payments' => []];

        $this->actingAs($this->cashier);
        $this->postJson('/api/v1/sales/preview', ['lines' => [$line]])->assertStatus(409)->assertJsonPath('code', 'discount_not_allowed');
        $before = Sale::count();
        $this->postJson('/api/v1/sales', $sale())->assertStatus(409)->assertJsonPath('code', 'discount_not_allowed');
        $this->assertSame($before, Sale::count());

        $this->actingAs($this->owner)->postJson('/api/v1/sales/preview', ['lines' => [$line]])->assertOk()->assertJsonPath('totals.total_paise', 0);
    }

    public function test_pharmacist_pin_guessing_is_locked_after_five_wrong_tries(): void
    {
        $lines = [['product_id' => Product::where('name', 'Mox 500 Capsule')->value('id'), 'qty' => 1]];
        $this->actingAs($this->cashier);
        $total = $this->postJson('/api/v1/sales/preview', ['lines' => $lines])->assertOk()->json('totals.total_paise');
        $attempt = fn (string $pin) => $this->postJson('/api/v1/sales', [
            'ulid' => (string) Str::ulid(), 'lines' => $lines, 'payments' => [['method' => 'cash', 'amount_paise' => $total]],
            'prescription' => ['prescriber_name' => 'Dr. A. Deshmukh', 'patient_name' => 'Lata Shinde'],
            'pharmacist' => ['user_id' => $this->pharmacist->id, 'pin' => $pin],
        ]);
        $before = Sale::count();

        for ($i = 0; $i < CompleteSale::PIN_ATTEMPTS; $i++) {
            $attempt('000'.$i)->assertStatus(409)->assertJsonPath('code', 'pharmacist_required');
        }
        // Even the right PIN is refused while locked.
        $attempt('1234')->assertStatus(409)->assertJsonPath('code', 'pin_locked');
        $this->assertSame($before, Sale::count());
        $this->assertSame(CompleteSale::PIN_ATTEMPTS, AuditLog::where('action', 'pharmacist.pin_failed')->count());

        RateLimiter::clear('pharmacist-pin:'.$this->pharmacist->id); // the wait is over
        $attempt('1234')->assertCreated();
    }

    public function test_medicine_permission_does_not_reach_general_products(): void
    {
        $general = Product::where('type', 'general')->first();
        $medicine = Product::where('type', 'medical')->first();
        $this->actingAs($this->pharmacist); // holds medicines.manage, not products.manage

        // Calling a general product "medical" in the request does not make it one.
        $this->putJson("/api/v1/products/{$general->id}", ['type' => 'medical', 'name' => 'Hijacked'])->assertForbidden();
        $this->assertSame('general', $general->refresh()->type);
        // On a real medicine the request is let in (and then fails validation because it is incomplete).
        $this->putJson("/api/v1/products/{$medicine->id}", ['type' => 'medical'])->assertUnprocessable();
    }

    public function test_an_interrupted_request_can_be_retried_after_a_minute_and_a_key_is_tied_to_its_address(): void
    {
        $this->actingAs($this->owner);
        $stuck = fn (string $key, int $secondsAgo, string $path = 'api/v1/customers') => DB::table('idempotency_keys')->insert([
            'user_id' => $this->owner->id, 'key' => $key, 'method' => 'POST', 'path' => $path, 'created_at' => now()->subSeconds($secondsAgo),
        ]);
        $post = fn (string $key) => $this->postJson('/api/v1/customers', ['name' => 'Retry '.$key], ['Idempotency-Key' => $key]);

        // Still inside the minute: it may really be running.
        $stuck('fresh-key', 5);
        $post('fresh-key')->assertStatus(409)->assertJsonPath('code', 'request_in_progress');

        // Abandoned by a crash: the retry goes through, once.
        $stuck('old-key', 300);
        $id = $post('old-key')->assertCreated()->json('id');
        $post('old-key')->assertCreated()->assertHeader('Idempotency-Replayed', 'true')->assertJsonPath('id', $id);
        $this->assertSame(1, DB::table('customers')->where('name', 'Retry old-key')->count());

        // A key that belongs to another address is refused, not answered with the other request's result.
        $stuck('other-key', 300, 'api/v1/products');
        $post('other-key')->assertStatus(422)->assertJsonPath('code', 'idempotency_key_reused');
    }

    public function test_sale_search_puts_the_named_product_first_and_what_is_in_stock_before_what_is_not(): void
    {
        $this->actingAs($this->cashier);
        $names = fn (string $q) => array_column($this->getJson('/api/v1/pos/search?q='.urlencode($q))->assertOk()->json('data'), 'name');

        // "mox" is inside "Amoxicillin" (Augmentin's salt), but the cashier means Mox.
        $this->assertSame('Mox 500 Capsule', $names('mox')[0]);
        $this->assertSame('Mox 500 Capsule', $names('mox 500')[0]);
        $this->assertContains('Augmentin 625 Duo Tablet', $names('mox')); // still found by its salt, just lower

        // Enter adds the first row, so it must be something that can be sold.
        $parle = $this->getJson('/api/v1/pos/search?q=parle')->json('data');
        $this->assertStringStartsWith('Parle-G', $parle[0]['name']);
        $this->assertGreaterThan(0, $parle[0]['stock_qty']);
        $stocks = array_map(fn ($row) => $row['stock_qty'] > 0, $this->getJson('/api/v1/pos/search?q=ta')->json('data'));
        $sorted = $stocks;
        rsort($sorted);
        $this->assertSame($sorted, $stocks, 'out-of-stock rows must come after in-stock rows');
    }

    public function test_expiry_lists_value_only_the_quantity_they_show(): void
    {
        $this->actingAs($this->owner);

        $aside = $this->getJson('/api/v1/stock/batches?status=unsellable')->assertOk()->json('data');
        $this->assertNotEmpty($aside);
        foreach ($aside as $row) {
            $rate = StockBatch::find($row['id'])->cost_rate;
            $this->assertSame(Rate::amount($rate, $row['qty_unsellable']), $row['value_paise'], $row['product_name']);
        }

        foreach ($this->getJson('/api/v1/stock/batches?status=expired')->assertOk()->json('data') as $row) {
            $rate = StockBatch::find($row['id'])->cost_rate;
            $this->assertSame(Rate::amount($rate, $row['qty_sellable']), $row['value_paise'], $row['product_name']);
        }
    }

    public function test_export_cells_are_safe_and_rates_print_whole(): void
    {
        $this->assertSame('0%', ReportExporter::percent('0'));
        $this->assertSame('40%', ReportExporter::percent('40'));
        $this->assertSame('5%', ReportExporter::percent('5.00'));
        $this->assertSame('2.5%', ReportExporter::percent('2.50'));

        foreach (['=1+1', '+SUM(A1)', '@cmd', "-1+cmd|' /C calc'!A0", "\t=1", ' =HYPERLINK("x")'] as $attack) {
            $this->assertSame("'".$attack, ReportExporter::csvSafe($attack));
        }
        foreach (['-3.00', '12.50', 'Crocin 500 Tablet', 'A-1 Rack', '', 15, null] as $harmless) {
            $this->assertSame($harmless, ReportExporter::csvSafe($harmless));
        }

        // The products export goes through the same guard.
        Product::where('type', 'general')->first()->update(['brand' => '=HYPERLINK("http://evil")']);
        $csv = $this->actingAs($this->owner)->get('/api/v1/products/export')->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
    }
}
