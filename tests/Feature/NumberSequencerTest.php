<?php

namespace Tests\Feature;

use App\Domain\Shared\FinancialYear;
use App\Domain\Shared\NumberSequencer;
use App\Models\Store;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NumberSequencerTest extends TestCase
{
    use RefreshDatabase;

    public function test_financial_year_runs_april_to_march_in_shop_time(): void
    {
        $this->assertSame('26-27', FinancialYear::codeFor(Carbon::parse('2026-11-11 10:00', 'Asia/Kolkata')));
        $this->assertSame('26-27', FinancialYear::codeFor(Carbon::parse('2027-03-31 23:59', 'Asia/Kolkata')));
        $this->assertSame('27-28', FinancialYear::codeFor(Carbon::parse('2027-04-01 00:00', 'Asia/Kolkata')));
        // 31 March 19:00 UTC is already 1 April 00:30 in India
        $this->assertSame('27-28', FinancialYear::codeFor(Carbon::parse('2027-03-31 19:00', 'UTC')));
    }

    public function test_numbers_are_consecutive_and_within_sixteen_characters(): void
    {
        $store = Store::create(['name' => 'Shop']);
        $at = Carbon::parse('2026-11-11 10:00', 'Asia/Kolkata');
        $sequencer = new NumberSequencer;

        $this->assertSame('A26-27/000001', $sequencer->next($store->id, 'sale', 'A', $at));
        $this->assertSame('A26-27/000002', $sequencer->next($store->id, 'sale', 'A', $at));
        $this->assertSame('26-27/000001', $sequencer->next($store->id, 'sale', '', $at));
        $this->assertSame('A26-27/000001', $sequencer->next($store->id, 'sale_return', 'A', $at));
        $this->assertLessThanOrEqual(16, strlen($sequencer->next($store->id, 'sale', 'AB', $at)));
    }

    public function test_series_restarts_each_financial_year(): void
    {
        $store = Store::create(['name' => 'Shop']);
        $sequencer = new NumberSequencer;

        $sequencer->next($store->id, 'sale', '', Carbon::parse('2027-03-31 12:00', 'Asia/Kolkata'));
        $this->assertSame('27-28/000001', $sequencer->next($store->id, 'sale', '', Carbon::parse('2027-04-01 12:00', 'Asia/Kolkata')));
    }

    public function test_number_is_not_used_up_when_the_document_fails_to_save(): void
    {
        $store = Store::create(['name' => 'Shop']);
        $at = Carbon::parse('2026-11-11 10:00', 'Asia/Kolkata');
        $sequencer = new NumberSequencer;

        try {
            DB::transaction(function () use ($sequencer, $store, $at) {
                $sequencer->next($store->id, 'sale', '', $at);
                throw new \RuntimeException('bill could not be saved');
            });
        } catch (\RuntimeException) {
        }

        $this->assertSame('26-27/000001', $sequencer->next($store->id, 'sale', '', $at));
    }
}
