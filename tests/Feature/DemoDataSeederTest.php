<?php

namespace Tests\Feature;

use App\Domain\Inventory\StockLedger;
use App\Domain\Parties\CustomerLedger;
use App\Domain\Shared\ShopClock;
use App\Models\Customer;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\StockBatch;
use App\Models\User;
use Database\Seeders\DemoDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DemoDataSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_demo_shop_is_complete_consistent_and_shows_every_stock_situation(): void
    {
        $this->seed(DemoDataSeeder::class);

        $this->assertSame(4, User::count());
        $this->assertSame(40, Product::where('type', 'medical')->count());
        $this->assertSame(40, Product::where('type', 'general')->count());
        $this->assertSame([], app(StockLedger::class)->findMismatches());
        $this->assertSame(0, StockBatch::where('qty_sellable', '<', 0)->orWhere('qty_unsellable', '<', 0)->count());

        // Every medicine batch has a batch number and an expiry date.
        $this->assertSame(0, StockBatch::whereHas('product', fn ($q) => $q->where('type', 'medical'))
            ->where(fn ($q) => $q->whereNull('batch_no')->orWhereNull('expiry_date'))->count());

        $owner = User::where('username', 'owner')->sole();
        $summary = $this->actingAs($owner)->getJson('/api/v1/stock/summary')->assertOk()->json();

        foreach (['low_stock', 'out_of_stock', 'expired_batches', 'expiring_90', 'schedule_not_set', 'stock_value_paise'] as $key) {
            $this->assertGreaterThan(0, $summary[$key], "Demo data should include: {$key}");
        }
        $this->assertSame(80, $summary['products']);

        // Ten bills today and 52 over the fortnight before, with money, udhar and stock all in step.
        $this->assertSame(62, Sale::count());
        $this->assertSame(10, Sale::whereDate('sale_date', ShopClock::todayString())->count());
        $this->assertSame(DemoDataSeeder::PAST_DAYS + 1, Sale::distinct()->count('sale_date'));
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
        $this->assertSame(4, Customer::where('balance_paise', '>', 0)->count());
        $this->assertSame(2, Sale::whereNotNull('prescription_id')->whereNotNull('verified_by_pharmacist_id')->count());
        $this->assertSame(
            (int) Sale::sum('total_paise'),
            (int) Payment::where('payable_type', (new Sale)->getMorphClass())->sum('amount_paise') + (int) Sale::sum('credit_paise'),
        );

        // All four demo logins work.
        foreach (['owner', 'pharmacist', 'manager', 'cashier'] as $username) {
            $this->postJson('/api/v1/auth/login', ['username' => $username, 'password' => DemoDataSeeder::PASSWORD])->assertOk();
        }
    }

    public function test_demo_data_refuses_to_load_over_an_existing_shop(): void
    {
        User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->seed(DemoDataSeeder::class);
    }
}
