<?php

namespace Tests\Unit;

use App\Domain\Shared\TaxCalculator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class TaxCalculatorTest extends TestCase
{
    public function test_splits_tax_inclusive_price_at_five_percent(): void
    {
        // Medicine strip MRP ₹105.00 including 5% GST
        $tax = TaxCalculator::fromInclusive(10500, '5');

        $this->assertSame(10000, $tax->taxablePaise);
        $this->assertSame(250, $tax->cgstPaise);
        $this->assertSame(250, $tax->sgstPaise);
        $this->assertSame(0, $tax->igstPaise);
        $this->assertSame(10500, $tax->totalPaise());
    }

    public function test_odd_paisa_goes_to_sgst_and_parts_add_up(): void
    {
        // ₹10.00 including 18%: taxable 8.47, tax 1.53
        $tax = TaxCalculator::fromInclusive(1000, '18');

        $this->assertSame(847, $tax->taxablePaise);
        $this->assertSame(76, $tax->cgstPaise);
        $this->assertSame(77, $tax->sgstPaise);
        $this->assertSame(1000, $tax->totalPaise());
    }

    public function test_nil_rate_has_no_tax(): void
    {
        $tax = TaxCalculator::fromInclusive(4200, '0');

        $this->assertSame(4200, $tax->taxablePaise);
        $this->assertSame(0, $tax->taxPaise());
    }

    public function test_inter_state_uses_igst_only(): void
    {
        $tax = TaxCalculator::fromInclusive(11800, '18', interState: true);

        $this->assertSame(10000, $tax->taxablePaise);
        $this->assertSame(1800, $tax->igstPaise);
        $this->assertSame(0, $tax->cgstPaise + $tax->sgstPaise);
    }

    public function test_cess_is_separated(): void
    {
        // ₹152.00 including 40% GST and 12% cess on a taxable value of ₹100.00
        $tax = TaxCalculator::fromInclusive(15200, '40', '12');

        $this->assertSame(10000, $tax->taxablePaise);
        $this->assertSame(1200, $tax->cessPaise);
        $this->assertSame(4000, $tax->cgstPaise + $tax->sgstPaise);
        $this->assertSame(15200, $tax->totalPaise());
    }

    public function test_exclusive_adds_tax_on_top(): void
    {
        // Supplier invoice: 10 boxes at ₹250.00 before 5% GST
        $tax = TaxCalculator::fromExclusive(250000, '5');

        $this->assertSame(250000, $tax->taxablePaise);
        $this->assertSame(6250, $tax->cgstPaise);
        $this->assertSame(6250, $tax->sgstPaise);
        $this->assertSame(262500, $tax->totalPaise());
    }

    #[DataProvider('amountsAndRates')]
    public function test_inclusive_split_never_loses_a_paisa(int $amount, string $rate): void
    {
        $tax = TaxCalculator::fromInclusive($amount, $rate);

        $this->assertSame($amount, $tax->totalPaise());
        $this->assertGreaterThanOrEqual(0, $tax->taxablePaise);
        $this->assertLessThanOrEqual(1, abs($tax->cgstPaise - $tax->sgstPaise));
    }

    public static function amountsAndRates(): iterable
    {
        foreach (['0', '0.25', '3', '5', '18', '40'] as $rate) {
            foreach ([1, 7, 99, 100, 101, 333, 1050, 9999, 123457] as $amount) {
                yield "{$amount} @ {$rate}%" => [$amount, $rate];
            }
        }
    }
}
