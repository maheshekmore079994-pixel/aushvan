<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\CancelExpense;
use App\Actions\CancelSale;
use App\Actions\CompleteSale;
use App\Actions\MoveCash;
use App\Actions\PaySupplier;
use App\Actions\RecordExpense;
use App\Actions\SaveProduct;
use App\Enums\RoleName;
use App\Models\CashMovement;
use App\Models\CashSession;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Store;
use App\Models\Supplier;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Tries to break the cash day: every payment kind in exactly one bucket, the window edges, reopening, and the guards. */
class DayClosingRobustnessTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();
        $this->at('2026-11-27 09:00');
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
        $this->soap = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => 'Lifebuoy Soap', 'tax_rate_id' => TaxRate::where('name', 'GST 18%')->value('id'),
            'base_unit_id' => Unit::where('code', 'PCS')->value('id'), 'mrp_paise' => 4000, 'selling_price_paise' => 4000,
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $this->soap, [['qty' => 100, 'cost_paise' => 3000]]);
        $this->actingAs($this->owner);
    }

    private function at(string $time): void
    {
        Carbon::setTestNow(Carbon::parse($time, 'Asia/Kolkata'));
    }

    private function keyedPost(string $uri, array $body = []): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function sell(int $pieces, array $payments): Sale
    {
        return app(CompleteSale::class)->handle($this->cashier, [
            'ulid' => (string) Str::ulid(),
            'lines' => [['product_id' => $this->soap->id, 'unit' => 'pack', 'qty' => $pieces, 'batch_id' => null, 'discount_percent' => 0]],
            'payments' => $payments,
        ]);
    }

    private function expected(): int
    {
        return (int) $this->getJson('/api/v1/day')->json('open.figures.expected_cash_paise');
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'name' => 'Mahalaxmi', 'search_text' => 'mahalaxmi'])->refresh();
    }

    public function test_every_kind_of_cash_payment_lands_in_exactly_one_bucket(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 50000])->assertCreated();
        $this->at('2026-11-27 09:10');

        $split = $this->sell(2, [['method' => 'cash', 'amount_paise' => 3000], ['method' => 'upi', 'amount_paise' => 5000]]); // Rs 80 bill
        $cancelled = $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);
        app(CancelSale::class)->handle($this->owner, $cancelled, 'Wrong item');                                     // Rs 40 cash back out

        $supplier = $this->supplier();
        app(PaySupplier::class)->handle($this->owner, $supplier, 12000, 'cash');                       // Rs 120 out
        $expense = app(RecordExpense::class)->handle($this->owner, ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 7000, 'method' => 'cash']);
        app(CancelExpense::class)->handle($this->owner, $expense, 'Twice');                             // Rs 70 out then back in
        app(MoveCash::class)->handle($this->owner, CashMovement::OWNER_WITHDRAWAL, 1000, 'Tea');       // Rs 10 out

        $figures = $this->getJson('/api/v1/day')->json('open.figures');
        $this->assertSame(7000, $figures['cash']['cash_sales']); // the cancelled bill's cash came in, and went back out below
        $this->assertSame(4000, $figures['cash']['refunds']);
        $this->assertSame(12000, $figures['cash']['paid_to_suppliers']);
        $this->assertSame(7000, $figures['cash']['expenses']);
        $this->assertSame(7000, $figures['cash']['other_in']); // the cancelled expense comes back as other cash in
        $this->assertSame(1000, $figures['cash']['cash_out_paise']);
        // 500 + 30 + 40 − 40 − 120 − 70 + 70 − 10 = 400
        $this->assertSame(40000, $figures['expected_cash_paise']);
        $this->assertSame(5000, $figures['non_cash']['upi']);
        $this->assertSame(1, $figures['bills']['count']); // the cancelled bill is not counted
        $this->assertSame(8000, $figures['bills']['total_paise']);
        $this->assertSame($split->id, Sale::where('status', 'completed')->value('id'));
        // Every payment made while the day was open is stamped with it.
        $this->assertSame(0, Payment::whereNull('cash_session_id')->count());
    }

    public function test_a_payment_in_the_same_second_as_the_closing_is_counted_once(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->at('2026-11-27 18:00:00');
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);               // same second as the close below
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 4000])->assertOk()->assertJsonPath('expected_cash_paise', 4000);

        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);               // same second, after the close: next drawer
        $this->at('2026-11-28 09:00');
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 4000])->assertCreated();
        $this->assertSame(8000, $this->expected());
        $this->assertSame(0, Payment::whereNull('cash_session_id')->count()); // made while no day was open: adopted by the next day
    }

    public function test_reopening_clears_the_count_and_closing_again_recomputes_it(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->at('2026-11-27 12:00');
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);
        $this->at('2026-11-27 18:00');
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 4000])->assertOk();
        $session = CashSession::firstOrFail();

        $this->at('2026-11-27 18:30');
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);               // forgotten bill, after the count
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Forgot a bill'])->assertOk()
            ->assertJsonPath('expected_cash_paise', null)->assertJsonPath('counted_cash_paise', null)->assertJsonPath('closed_at', null)
            ->assertJsonPath('figures.expected_cash_paise', 8000);
        $this->at('2026-11-27 18:40');
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 8000])->assertOk()->assertJsonPath('expected_cash_paise', 8000)->assertJsonPath('difference_paise', 0);
        $this->assertSame(1, CashSession::count());
        $this->assertSame(2, Payment::where('cash_session_id', $session->id)->count());
    }

    public function test_a_day_open_across_midnight_keeps_counting_and_the_next_date_cannot_open_until_it_is_closed(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->at('2026-11-28 00:30');
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertStatus(409)->assertJsonPath('code', 'day_already_open');
        $this->assertSame(4000, $this->expected());
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 4000])->assertOk()->assertJsonPath('business_date', '2026-11-27');
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 4000])->assertCreated()->assertJsonPath('business_date', '2026-11-28');
    }

    public function test_withdrawals_stop_at_the_drawer_and_expenses_are_bounded(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 10000])->assertCreated();
        $this->keyedPost('/api/v1/day/movements', ['type' => 'bank_deposit', 'amount_paise' => 10001])->assertStatus(409)->assertJsonPath('code', 'not_enough_cash');
        $this->keyedPost('/api/v1/day/movements', ['type' => 'bank_deposit', 'amount_paise' => 10000])->assertCreated();
        $this->assertSame(0, $this->expected());
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 500])->assertCreated(); // putting in never fails
        $this->keyedPost('/api/v1/day/movements', ['type' => 'robbery', 'amount_paise' => 1])->assertStatus(422);
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 0])->assertStatus(422);

        $rent = $this->categoryId('Rent');
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $rent, 'amount_paise' => 100000000001, 'method' => 'cash'])->assertStatus(422);
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $rent, 'amount_paise' => 10, 'method' => 'cheque'])->assertStatus(422);
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $rent, 'amount_paise' => 10, 'method' => 'cash', 'expense_date' => '2024-01-01'])->assertStatus(422);
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $rent, 'amount_paise' => 10, 'method' => 'cash', 'description' => str_repeat('x', 301)])->assertStatus(422);
        $this->putJson("/api/v1/expense-categories/{$rent}", ['is_active' => false])->assertOk();
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $rent, 'amount_paise' => 10, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'expense_category_missing');
    }

    public function test_expense_heads_cannot_collide_and_blank_names_are_refused(): void
    {
        $id = $this->keyedPost('/api/v1/expense-categories', ['name' => '  Donation  '])->assertCreated()->json('id');
        $this->keyedPost('/api/v1/expense-categories', ['name' => 'donation'])->assertCreated()->assertJsonPath('id', $id); // same head, any case
        $this->keyedPost('/api/v1/expense-categories', ['name' => '   '])->assertStatus(422);
        $this->putJson("/api/v1/expense-categories/{$id}", ['name' => 'Rent'])->assertStatus(422);
        $this->putJson("/api/v1/expense-categories/{$id}", ['name' => '  '])->assertStatus(422);
        $this->putJson("/api/v1/expense-categories/{$id}", ['name_local' => 'देणगी'])->assertOk()->assertJsonPath('name_local', 'देणगी');
        $this->putJson("/api/v1/expense-categories/{$id}", ['name_local' => null])->assertOk()->assertJsonPath('name_local', null);
    }

    public function test_other_shops_days_expenses_and_heads_are_out_of_reach(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $expense = app(RecordExpense::class)->handle($this->owner, ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 100, 'method' => 'cash']);
        $session = CashSession::firstOrFail();

        $other = Store::create(['name' => 'Other']);
        (new ReferenceDataSeeder)->run();
        $stranger = User::factory()->create(['store_id' => $other->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger);
        $this->getJson("/api/v1/day/{$session->id}")->assertNotFound();
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Mine now'])->assertNotFound();
        $this->keyedPost("/api/v1/expenses/{$expense->id}/cancel", ['reason' => 'Mine now'])->assertNotFound();
        $this->putJson('/api/v1/expense-categories/'.$this->categoryId('Rent'), ['name' => 'Hijack'])->assertNotFound();
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 10, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'expense_category_missing');
        $this->getJson('/api/v1/day')->assertOk()->assertJsonPath('open', null);
        $this->assertSame(1, Expense::count());
    }

    public function test_movements_and_closed_days_are_frozen_and_audited(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 100])->assertCreated();
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 50, 'note' => 'float'])->assertCreated();
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 150])->assertOk();
        foreach (['day.opened', 'day.cash_moved', 'day.closed'] as $action) {
            $this->assertDatabaseHas('audit_logs', ['action' => $action]);
        }
        $movement = CashMovement::firstOrFail();
        try {
            $movement->update(['amount_paise' => 1]);
            $this->fail('movement changed');
        } catch (\LogicException) {
        }
        try {
            $movement->delete();
            $this->fail('movement deleted');
        } catch (\LogicException) {
        }
        try {
            CashSession::firstOrFail()->delete();
            $this->fail('day deleted');
        } catch (\LogicException) {
        }
        $this->assertSame(1, CashMovement::count());
    }

    private function categoryId(string $name): int
    {
        return (int) ExpenseCategory::where('store_id', $this->owner->store_id)->where('name', $name)->value('id');
    }
}
