<?php

namespace Tests\Feature;

use App\Enums\RoleName;
use App\Models\AuditLog;
use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Dashboard numbers, reports and their exports, checked against the demo shop's own records. */
class ReportsTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(DemoDataSeeder::class);
        $this->owner = User::where('username', 'owner')->sole();
    }

    private function report(string $key, array $query = ['from' => '2000-01-01', 'to' => '2099-12-31']): array
    {
        return $this->actingAs($this->owner)->getJson("/api/v1/reports/{$key}?".http_build_query($query))->assertOk()->json();
    }

    public function test_sales_reports_agree_with_the_bills(): void
    {
        $total = (int) Sale::sum('total_paise');

        $byDay = $this->report('sales_by_day');
        $this->assertSame($total, $byDay['totals']['total']);
        $this->assertSame(Sale::count(), $byDay['totals']['bills']);
        $this->assertSame((int) Sale::sum('credit_paise'), $byDay['totals']['udhar']);
        // Every rupee of a bill is either paid by some method or left as udhar.
        $paid = $byDay['totals']['cash'] + $byDay['totals']['upi'] + $byDay['totals']['card'] + $byDay['totals']['bank'];
        $this->assertSame($total, $paid + $byDay['totals']['udhar']);
        $this->assertSame((int) Sale::sum('taxable_paise') - (int) Sale::sum('cost_paise'), $byDay['totals']['profit']);

        $this->assertSame($total - (int) Sale::sum('round_off_paise'), $this->report('sales_by_item')['totals']['amount']);
        $this->assertSame($total, $this->report('bills')['totals']['total']);
        $this->assertCount(Sale::count(), $this->report('bills')['rows']);

        $gst = $this->report('gst_summary');
        $this->assertSame((int) Sale::sum('taxable_paise'), $gst['totals']['taxable']);
        $this->assertSame((int) Sale::sum('cgst_paise'), $gst['totals']['cgst']);
    }

    public function test_stock_and_udhar_reports_agree_with_the_records(): void
    {
        $summary = $this->actingAs($this->owner)->getJson('/api/v1/stock/summary')->json();

        $stock = $this->report('stock_summary', []);
        $this->assertCount(Product::where('is_active', true)->count(), $stock['rows']);
        $this->assertSame($summary['stock_value_paise'], $stock['totals']['value']);
        $this->assertCount(40, $this->report('stock_summary', ['type' => 'medical'])['rows']);

        $this->assertCount($summary['low_stock'] + $summary['out_of_stock'], $this->report('reorder', [])['rows']);
        $this->assertCount($summary['expired_batches'] + $summary['expiring_90'], $this->report('expiry', ['days' => 90])['rows']);
        $this->assertNotEmpty($this->report('stock_movements')['rows']);
        $this->assertNotEmpty($this->report('slow_moving', ['days' => 1])['rows']);

        $udhar = $this->report('udhar_outstanding', []);
        $this->assertSame((int) Customer::sum('balance_paise'), $udhar['totals']['balance']);
    }

    public function test_reports_need_permission_and_hide_profit_and_cost(): void
    {
        $cashier = User::where('username', 'cashier')->sole();
        $this->actingAs($cashier)->getJson('/api/v1/reports/sales_by_day')->assertForbidden();
        $this->actingAs($cashier)->get('/api/v1/reports/sales_by_day/export?format=csv')->assertForbidden();

        // The pharmacist may read reports but not profit or cost.
        $pharmacist = User::where('username', 'pharmacist')->sole();
        $columns = fn (string $key) => array_column($this->actingAs($pharmacist)->getJson("/api/v1/reports/{$key}")->assertOk()->json('columns'), 'key');
        $this->assertNotContains('profit', $columns('sales_by_day'));
        $this->assertNotContains('value', $columns('stock_summary'));
        $this->assertContains('profit', array_column($this->report('sales_by_day')['columns'], 'key'));

        $this->actingAs($this->owner)->getJson('/api/v1/reports/nothing')->assertNotFound();
        $this->actingAs($this->owner)->getJson('/api/v1/reports/bills?from=2026-02-01&to=2026-01-01')->assertUnprocessable();
    }

    public function test_exports_as_csv_excel_and_pdf(): void
    {
        $this->actingAs($this->owner);

        $csv = $this->get('/api/v1/reports/sales_by_item/export?format=csv&lang=mr&from=2000-01-01&to=2099-12-31')->assertOk();
        $this->assertStringContainsString('.csv', $csv->headers->get('content-disposition'));
        $content = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringStartsWith("\xEF\xBB\xBF", $content);
        $this->assertStringContainsString('उत्पादन', $content); // Marathi column heading
        $this->assertStringContainsString('Crocin', $content);

        $xlsx = $this->get('/api/v1/reports/stock_summary/export?format=xlsx')->assertOk();
        $this->assertStringStartsWith('PK', file_get_contents($xlsx->baseResponse->getFile()->getPathname()));

        $pdf = $this->get('/api/v1/reports/udhar_outstanding/export?format=pdf&lang=hi')->assertOk();
        $this->assertStringStartsWith('%PDF', file_get_contents($pdf->baseResponse->getFile()->getPathname()));

        $this->get('/api/v1/reports/bills/export?format=doc', ['Accept' => 'application/json'])->assertStatus(422);
        $this->assertSame(3, AuditLog::where('action', 'reports.exported')->count());
    }

    public function test_dashboard_shows_each_role_only_what_it_may_see(): void
    {
        $ownerView = $this->actingAs($this->owner)->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertCount(14, $ownerView['trend']);
        $this->assertSame((int) Sale::where('sale_date', $ownerView['today'])->sum('total_paise'), $ownerView['sales']['today']['sales_paise']);
        $this->assertArrayHasKey('profit_paise', $ownerView['sales']['today']);
        $this->assertSame((int) Customer::where('balance_paise', '>', 0)->sum('balance_paise'), $ownerView['udhar']['total_paise']);
        $this->assertNotEmpty($ownerView['top_products']);
        $this->assertNotEmpty($ownerView['low_stock']);
        $this->assertNotEmpty($ownerView['expiring']);
        $this->assertNotEmpty($ownerView['recent_bills']);

        $cashierView = $this->actingAs(User::where('username', 'cashier')->sole())->getJson('/api/v1/dashboard')->assertOk()->json();
        $this->assertArrayNotHasKey('sales', $cashierView);
        $this->assertArrayNotHasKey('trend', $cashierView);
        $this->assertArrayNotHasKey('profit_paise', $cashierView['mine']);
        $this->assertArrayHasKey('recent_bills', $cashierView);

        $pharmacistView = $this->actingAs(User::where('username', 'pharmacist')->sole())->getJson('/api/v1/dashboard')->json();
        $this->assertArrayHasKey('sales', $pharmacistView);
        $this->assertArrayNotHasKey('profit_paise', $pharmacistView['sales']['today']);

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/v1/dashboard')->assertUnauthorized();
    }

    public function test_role_enum_is_not_needed_for_reports(): void
    {
        // A custom role holding only "reports.view" can open reports.
        $this->actingAs($this->owner)->postJson('/api/v1/roles', ['name' => 'Accountant', 'permissions' => ['reports.view']])->assertCreated();
        $user = User::factory()->create(['store_id' => $this->owner->store_id]);
        $user->assignRole('Accountant');

        $this->actingAs($user->refresh())->getJson('/api/v1/reports/udhar_outstanding')->assertOk();
        $this->assertSame(RoleName::Owner->value, $this->owner->getRoleNames()->first());
    }
}
