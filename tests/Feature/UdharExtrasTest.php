<?php

namespace Tests\Feature;

use App\Domain\Parties\CustomerLedger;
use App\Enums\RoleName;
use App\Models\Customer;
use App\Models\CustomerLedgerEntry;
use App\Models\Payment;
use App\Models\Sale;
use App\Models\Store;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/** Phase 5 extras: opening balances, reversal and adjustment entries, overdue flag (J3, J5, I5). */
class UdharExtrasTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00', 'Asia/Kolkata'));
        $this->owner = $this->userWithRole(RoleName::Owner);
        $this->cashier = $this->userWithRole(RoleName::Cashier);
        $this->actingAs($this->owner);
    }

    private function keyedPost(string $uri, array $body = []): TestResponse
    {
        return $this->postJson($uri, $body, ['Idempotency-Key' => (string) Str::uuid()]);
    }

    public function test_a_customer_can_start_with_the_udhar_from_the_paper_book(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Ramesh Patil', 'mobile' => '9800000010', 'opening_balance_paise' => 125000])
            ->assertCreated()->assertJsonPath('balance_paise', 125000)->json('id');
        $this->getJson("/api/v1/customers/{$id}")->assertOk()->assertJsonPath('entries.0.type', 'opening')->assertJsonPath('entries.0.debit_paise', 125000)->assertJsonPath('entries.0.balance_after_paise', 125000);

        // An advance the shop already holds is a negative opening balance.
        $this->postJson('/api/v1/customers', ['name' => 'Sunita', 'opening_balance_paise' => -5000])->assertCreated()->assertJsonPath('balance_paise', -5000);
        // Not on an edit.
        $this->putJson("/api/v1/customers/{$id}", ['name' => 'Ramesh Patil', 'opening_balance_paise' => 1])->assertStatus(422);
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
    }

    public function test_a_wrong_payment_is_reversed_in_full_with_a_reason_and_only_once(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Ganesh', 'opening_balance_paise' => 100000])->json('id');
        $this->keyedPost("/api/v1/customers/{$id}/payments", ['amount_paise' => 40000, 'method' => 'cash'])->assertCreated()->assertJsonPath('balance_paise', 60000);
        $payment = CustomerLedgerEntry::where('customer_id', $id)->where('type', 'payment')->firstOrFail();

        $this->actingAs($this->cashier);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$payment->id}/reverse", ['reason' => 'Wrong customer'])->assertForbidden();

        $this->actingAs($this->owner);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$payment->id}/reverse", ['reason' => 'ok'])->assertStatus(422);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$payment->id}/reverse", ['reason' => 'Wrong customer'])->assertCreated()->assertJsonPath('balance_paise', 100000);
        $this->assertDatabaseHas('customer_ledger_entries', ['customer_id' => $id, 'type' => 'reversal', 'debit_paise' => 40000, 'reverses_id' => $payment->id, 'note' => 'Wrong customer']);
        $this->assertDatabaseHas('audit_logs', ['action' => 'credit.reversed', 'reason' => 'Wrong customer']);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$payment->id}/reverse", ['reason' => 'Again'])->assertStatus(409)->assertJsonPath('code', 'entry_already_reversed');
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());

        // The cash goes back out on paper too, so receiving it again on the right customer does not count twice.
        $this->assertDatabaseHas('payments', ['direction' => 'out', 'method' => 'cash', 'amount_paise' => 40000, 'party_id' => $id, 'note' => 'Wrong customer']);
        $this->assertSame(2, Payment::count());
        $this->assertNull(Customer::find($id)->last_payment_at); // the reversed payment was never theirs
    }

    public function test_bills_are_not_reversed_here_and_other_shops_entries_are_out_of_reach(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Lata', 'opening_balance_paise' => 100])->json('id');
        $opening = CustomerLedgerEntry::where('customer_id', $id)->firstOrFail();
        $sale = CustomerLedgerEntry::create([
            'ulid' => (string) Str::ulid(), 'store_id' => $this->owner->store_id, 'customer_id' => $id, 'entry_date' => '2026-11-27', 'type' => 'sale',
            'debit_paise' => 500, 'credit_paise' => 0, 'balance_after_paise' => 600, 'user_id' => $this->owner->id, 'created_at' => now(),
        ]);
        Customer::whereKey($id)->update(['balance_paise' => 600]);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$sale->id}/reverse", ['reason' => 'Try a bill'])->assertStatus(409)->assertJsonPath('code', 'entry_not_reversible');

        $otherId = $this->postJson('/api/v1/customers', ['name' => 'Vitthal'])->json('id');
        $this->keyedPost("/api/v1/customers/{$otherId}/entries/{$opening->id}/reverse", ['reason' => 'Cross-wired'])->assertNotFound();

        $other = Store::create(['name' => 'Other']);
        $stranger = User::factory()->create(['store_id' => $other->id]);
        $stranger->assignRole(RoleName::Owner->value);
        $this->actingAs($stranger);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$opening->id}/reverse", ['reason' => 'Not mine'])->assertNotFound();
        $this->keyedPost("/api/v1/customers/{$id}/adjustments", ['direction' => 'down', 'amount_paise' => 1, 'reason' => 'Not mine'])->assertNotFound();
    }

    public function test_money_taken_on_a_bill_is_not_undone_from_the_statement(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Ramesh'])->json('id');
        $customer = Customer::findOrFail($id);
        // A part payment taken on a bill: its money row names the bill.
        $payment = Payment::create([
            'ulid' => (string) Str::ulid(), 'store_id' => $customer->store_id, 'direction' => 'in', 'method' => 'cash', 'amount_paise' => 20000,
            'party_type' => $customer->getMorphClass(), 'party_id' => $customer->id, 'payable_type' => (new Sale)->getMorphClass(), 'payable_id' => 1,
            'business_date' => '2026-11-27', 'user_id' => $this->owner->id, 'created_at' => now(),
        ]);
        $entry = DB::transaction(fn () => app(CustomerLedger::class)->post($customer, 'payment', 0, 20000, $payment, $this->owner->id));

        $this->getJson("/api/v1/customers/{$id}")->assertOk()->assertJsonPath('entries.0.reversible', false);
        $this->keyedPost("/api/v1/customers/{$id}/entries/{$entry->id}/reverse", ['reason' => 'Wrong customer'])->assertStatus(409)->assertJsonPath('code', 'entry_not_reversible');
        $this->assertSame(1, Payment::count());
    }

    public function test_an_adjustment_writes_off_or_adds_udhar_with_a_reason(): void
    {
        $id = $this->postJson('/api/v1/customers', ['name' => 'Anganwadi', 'opening_balance_paise' => 100050])->json('id');
        $this->keyedPost("/api/v1/customers/{$id}/adjustments", ['direction' => 'down', 'amount_paise' => 50, 'reason' => 'Rounded off at settlement'])->assertCreated()->assertJsonPath('balance_paise', 100000);
        $this->keyedPost("/api/v1/customers/{$id}/adjustments", ['direction' => 'up', 'amount_paise' => 2000, 'reason' => 'Missed line on paper bill'])->assertCreated()->assertJsonPath('balance_paise', 102000);
        $this->keyedPost("/api/v1/customers/{$id}/adjustments", ['direction' => 'sideways', 'amount_paise' => 1, 'reason' => 'Nope'])->assertStatus(422);
        $this->keyedPost("/api/v1/customers/{$id}/adjustments", ['direction' => 'down', 'amount_paise' => 0, 'reason' => 'Nope'])->assertStatus(422);
        $this->postJson("/api/v1/customers/{$id}/adjustments", ['direction' => 'down', 'amount_paise' => 1, 'reason' => 'No key'])->assertStatus(400);
        $this->assertSame(2, CustomerLedgerEntry::where('customer_id', $id)->where('type', 'adjustment')->count());
        $this->assertSame([], app(CustomerLedger::class)->findMismatches());
    }

    public function test_the_udhar_list_says_how_long_since_the_last_payment_and_flags_overdue_accounts(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-10-01 10:00', 'Asia/Kolkata'));
        $old = $this->postJson('/api/v1/customers', ['name' => 'Old Debt', 'opening_balance_paise' => 50000])->json('id');
        $paid = $this->postJson('/api/v1/customers', ['name' => 'Paid Up', 'opening_balance_paise' => 50000])->json('id');
        Carbon::setTestNow(Carbon::parse('2026-11-20 10:00', 'Asia/Kolkata'));
        $this->keyedPost("/api/v1/customers/{$paid}/payments", ['amount_paise' => 10000, 'method' => 'cash'])->assertCreated();
        Carbon::setTestNow(Carbon::parse('2026-11-27 10:00', 'Asia/Kolkata'));

        $list = $this->getJson('/api/v1/customers?owing=1')->assertOk();
        $rows = collect($list->json('data'))->keyBy('id');
        $this->assertSame(57, $rows[$old]['days_since_payment']);
        $this->assertTrue($rows[$old]['overdue']);
        $this->assertSame(7, $rows[$paid]['days_since_payment']);
        $this->assertFalse($rows[$paid]['overdue']);

        // The shop can set its own limit.
        $this->owner->store->putSetting('udhar_overdue_days', 60);
        $this->owner->unsetRelation('store');
        $this->assertFalse(collect($this->getJson('/api/v1/customers?owing=1')->json('data'))->keyBy('id')[$old]['overdue']);
    }
}
