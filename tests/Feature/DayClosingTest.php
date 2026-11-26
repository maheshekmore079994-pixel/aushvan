<?php

namespace Tests\Feature;

use App\Actions\AddOpeningStock;
use App\Actions\CompleteSale;
use App\Actions\ReceiveCustomerPayment;
use App\Actions\SaveProduct;
use App\Enums\RoleName;
use App\Models\CashSession;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\ExpenseCategory;
use App\Models\Payment;
use App\Models\Product;
use App\Models\Store;
use App\Models\TaxRate;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Scenario S12 (day closing) and the expenses that feed it. */
class DayClosingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    private Product $soap;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-27 09:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
        $this->soap = app(SaveProduct::class)->handle($this->owner, [
            'type' => 'general', 'name' => 'Lifebuoy Soap', 'tax_rate_id' => TaxRate::where('name', 'GST 18%')->value('id'),
            'base_unit_id' => Unit::where('code', 'PCS')->value('id'), 'mrp_paise' => 4000, 'selling_price_paise' => 4000,
        ]);
        app(AddOpeningStock::class)->handle($this->owner, $this->soap, [['qty' => 100, 'cost_paise' => 3000]]);
        $this->actingAs($this->cashier);
    }

    private function keyedPost(string $uri, array $body = []): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    private function sell(int $pieces, array $payments, ?Customer $customer = null): void
    {
        app(CompleteSale::class)->handle($this->cashier, [
            'ulid' => (string) Str::ulid(),
            'lines' => [['product_id' => $this->soap->id, 'unit' => 'pack', 'qty' => $pieces, 'batch_id' => null, 'discount_percent' => 0]],
            'payments' => $payments,
            'customer_id' => $customer?->id,
        ]);
    }

    private function customer(): Customer
    {
        return Customer::create(['ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'name' => 'Ramesh', 'search_text' => 'ramesh'])->refresh();
    }

    public function test_the_day_opens_counts_every_cash_movement_and_closes_with_the_difference(): void
    {
        $this->getJson('/api/v1/day')->assertOk()->assertJsonPath('open', null)->assertJsonPath('suggested_opening_paise', 0);
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 100000])->assertCreated()->assertJsonPath('status', 'open');
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 5])->assertStatus(409)->assertJsonPath('code', 'day_already_open');

        Carbon::setTestNow(Carbon::parse('2026-11-27 09:05', 'Asia/Kolkata')); // the shop trades
        $customer = $this->customer();
        $this->sell(2, [['method' => 'cash', 'amount_paise' => 8000]]);                                   // cash sale Rs 80
        $this->sell(1, [['method' => 'upi', 'amount_paise' => 4000]]);                                    // UPI sale
        $this->sell(3, [['method' => 'cash', 'amount_paise' => 5000]], $customer);                        // Rs 50 cash + Rs 70 udhar
        app(ReceiveCustomerPayment::class)->handle($this->cashier, $customer, 3000, 'cash');              // udhar received in cash
        app(ReceiveCustomerPayment::class)->handle($this->cashier, $customer, 2000, 'upi');               // udhar received by UPI

        $this->actingAs($this->owner);
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Tea & snacks'), 'amount_paise' => 2500, 'method' => 'cash', 'description' => 'Tea for staff'])->assertCreated();
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Electricity'), 'amount_paise' => 180000, 'method' => 'upi'])->assertCreated(); // not cash
        $this->keyedPost('/api/v1/day/movements', ['type' => 'bank_deposit', 'amount_paise' => 50000, 'note' => 'Deposited at SBI'])->assertCreated();
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 10000, 'note' => 'Change from home'])->assertCreated();
        $this->keyedPost('/api/v1/day/movements', ['type' => 'owner_withdrawal', 'amount_paise' => 99999999])->assertStatus(409)->assertJsonPath('code', 'not_enough_cash');

        $day = $this->getJson('/api/v1/day')->assertOk();
        // 1,000 + 80 + 50 (cash sales) + 30 (udhar) − 25 (expense) − 500 (bank) + 100 (cash in) = 735
        $day->assertJsonPath('open.figures.cash.cash_sales', 13000)
            ->assertJsonPath('open.figures.cash.udhar_received', 3000)
            ->assertJsonPath('open.figures.cash.expenses', 2500)
            ->assertJsonPath('open.figures.cash.cash_out_paise', 50000)
            ->assertJsonPath('open.figures.cash.cash_in_paise', 10000)
            ->assertJsonPath('open.figures.expected_cash_paise', 73500)
            ->assertJsonPath('open.figures.non_cash.upi', 6000)
            ->assertJsonPath('open.figures.bills.count', 3)
            ->assertJsonPath('open.figures.bills.udhar_paise', 7000);

        Carbon::setTestNow(Carbon::parse('2026-11-27 18:00', 'Asia/Kolkata'));
        $closed = $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 73000, 'note' => 'Rs 5 short'])->assertOk();
        $closed->assertJsonPath('status', 'closed')->assertJsonPath('expected_cash_paise', 73500)->assertJsonPath('difference_paise', -500);
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 1])->assertStatus(409)->assertJsonPath('code', 'day_not_open');

        // A sale after closing goes into the next day's drawer, not the frozen one.
        Carbon::setTestNow(Carbon::parse('2026-11-27 21:00', 'Asia/Kolkata'));
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);
        $session = CashSession::firstOrFail();
        $this->getJson("/api/v1/day/{$session->id}")->assertOk()->assertJsonPath('figures.expected_cash_paise', 73500)->assertJsonPath('figures.cash.cash_sales', 13000);
        $this->getJson('/api/v1/day')->assertOk()->assertJsonPath('suggested_opening_paise', 73000);

        Carbon::setTestNow(Carbon::parse('2026-11-28 09:00', 'Asia/Kolkata'));
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 73000])->assertCreated();
        $this->getJson('/api/v1/day')->assertOk()->assertJsonPath('open.figures.cash.cash_sales', 4000)->assertJsonPath('open.figures.expected_cash_paise', 77000);
        $this->getJson('/api/v1/day/history')->assertOk()->assertJsonCount(2, 'data');
    }

    public function test_only_the_owner_reopens_the_latest_closed_day_with_a_reason(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 0])->assertOk();
        $session = CashSession::firstOrFail();

        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Forgot a bill'])->assertForbidden();
        $this->actingAs($this->owner);
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'x'])->assertStatus(422);
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Forgot a bill'])->assertOk()->assertJsonPath('status', 'open')->assertJsonPath('reopened.reason', 'Forgot a bill');
        $this->assertDatabaseHas('audit_logs', ['action' => 'day.reopened', 'reason' => 'Forgot a bill']);
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'day_not_closed');
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 0])->assertOk();

        // Yesterday cannot be reopened once a later day exists.
        Carbon::setTestNow(Carbon::parse('2026-11-28 09:00', 'Asia/Kolkata'));
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Too late'])->assertStatus(409)->assertJsonPath('code', 'day_already_open');
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 0])->assertOk();
        $this->keyedPost("/api/v1/day/{$session->id}/reopen", ['reason' => 'Too late'])->assertStatus(409)->assertJsonPath('code', 'day_not_latest');
    }

    public function test_a_closed_day_cannot_be_opened_twice_on_the_same_date(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 0])->assertOk();
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertStatus(409)->assertJsonPath('code', 'day_already_closed');
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 100])->assertStatus(409)->assertJsonPath('code', 'day_not_open');
    }

    public function test_expenses_are_listed_summed_by_head_and_cancelled_with_a_mirrored_payment(): void
    {
        $this->actingAs($this->owner);
        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonFragment(['name' => 'Rent']);
        $rent = $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 500000, 'method' => 'bank', 'expense_date' => '2026-11-01', 'reference_no' => 'NEFT1'])->assertCreated()->json('id');
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Tea & snacks'), 'amount_paise' => 3000, 'method' => 'cash'])->assertCreated();
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => 999999, 'amount_paise' => 3000, 'method' => 'cash'])->assertStatus(409)->assertJsonPath('code', 'expense_category_missing');
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 0, 'method' => 'cash'])->assertStatus(422);
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => $this->categoryId('Rent'), 'amount_paise' => 10, 'method' => 'cash', 'expense_date' => '2027-01-01'])->assertStatus(422);

        $list = $this->getJson('/api/v1/expenses?from=2026-11-01&to=2026-11-30')->assertOk();
        $list->assertJsonCount(2, 'data')->assertJsonPath('total_paise', 503000)->assertJsonCount(2, 'by_category');
        $this->assertDatabaseHas('payments', ['direction' => 'out', 'method' => 'bank', 'amount_paise' => 500000, 'payable_id' => $rent]);

        $this->keyedPost("/api/v1/expenses/{$rent}/cancel", ['reason' => 'Entered twice'])->assertOk()->assertJsonPath('cancelled.reason', 'Entered twice');
        $this->keyedPost("/api/v1/expenses/{$rent}/cancel", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'expense_cancelled');
        $this->assertDatabaseHas('payments', ['direction' => 'in', 'method' => 'bank', 'amount_paise' => 500000, 'payable_id' => $rent]);
        $this->getJson('/api/v1/expenses?from=2026-11-01&to=2026-11-30')->assertOk()->assertJsonPath('total_paise', 3000);
        $this->assertSame(1, Expense::whereNotNull('cancelled_at')->count());

        // A new head, renamed, then hidden.
        $id = $this->keyedPost('/api/v1/expense-categories', ['name' => 'Donation'])->assertCreated()->json('id');
        $this->putJson("/api/v1/expense-categories/{$id}", ['name' => 'Donations & festivals', 'is_active' => false])->assertOk()->assertJsonPath('is_active', false);
        $this->getJson('/api/v1/expense-categories')->assertOk()->assertJsonMissing(['name' => 'Donations & festivals']);
        $this->getJson('/api/v1/expense-categories?all=1')->assertOk()->assertJsonFragment(['name' => 'Donations & festivals']);
    }

    public function test_a_cashier_may_see_and_close_the_day_but_not_enter_expenses_or_reopen(): void
    {
        $this->getJson('/api/v1/day')->assertOk();
        $this->getJson('/api/v1/expenses')->assertForbidden();
        $this->keyedPost('/api/v1/expenses', ['expense_category_id' => 1, 'amount_paise' => 1, 'method' => 'cash'])->assertForbidden();
        $this->postJson('/api/v1/day/open', ['opening_cash_paise' => 0])->assertStatus(400); // repeat-safety key required
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();

        $other = Store::create(['name' => 'Other Shop']);
        $stranger = User::factory()->create(['store_id' => $other->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger);
        $this->getJson('/api/v1/day')->assertOk()->assertJsonPath('open', null);
        $this->getJson('/api/v1/day/'.CashSession::first()->id)->assertNotFound();
    }

    public function test_frozen_days_and_movements_refuse_edits_at_the_model_level(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 100])->assertCreated();
        $this->keyedPost('/api/v1/day/movements', ['type' => 'cash_in', 'amount_paise' => 50])->assertCreated();
        $this->keyedPost('/api/v1/day/close', ['counted_cash_paise' => 150])->assertOk();
        $session = CashSession::firstOrFail();

        $this->expectException(\LogicException::class);
        $session->update(['counted_cash_paise' => 1]);
    }

    public function test_a_payment_row_cannot_be_moved_between_days(): void
    {
        $this->keyedPost('/api/v1/day/open', ['opening_cash_paise' => 0])->assertCreated();
        $this->sell(1, [['method' => 'cash', 'amount_paise' => 4000]]);
        $this->expectException(\LogicException::class);
        Payment::first()->update(['business_date' => '2026-01-01']);
    }

    private function categoryId(string $name): int
    {
        return (int) ExpenseCategory::where('store_id', $this->owner->store_id)->where('name', $name)->value('id');
    }
}
