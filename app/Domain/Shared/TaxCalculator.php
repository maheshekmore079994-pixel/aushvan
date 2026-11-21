<?php

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * GST arithmetic. Rates always arrive as data (decimal strings from the
 * tax_rates table or a transaction line); no rate is defined in code.
 */
final class TaxCalculator
{
    /**
     * Retail prices include tax. Split a tax-inclusive line amount into
     * taxable value and tax. The parts always add back to the amount given.
     */
    public static function fromInclusive(
        int $inclusivePaise,
        string $gstPercent,
        string $cessPercent = '0',
        bool $interState = false,
    ): TaxBreakdown {
        $gst = self::rate($gstPercent);
        $cess = self::rate($cessPercent);
        $divisor = BigDecimal::of(100)->plus($gst)->plus($cess);

        $taxable = BigDecimal::of($inclusivePaise)
            ->multipliedBy(100)
            ->dividedBy($divisor, 0, RoundingMode::HalfUp)
            ->toInt();

        $cessPaise = BigDecimal::of($taxable)->multipliedBy($cess)
            ->dividedBy(100, 0, RoundingMode::HalfUp)->toInt();
        $gstPaise = $inclusivePaise - $taxable - $cessPaise;

        return self::split($taxable, $gstPaise, $cessPaise, $interState);
    }

    /** Supplier invoices quote rates before tax. Add tax on top of a taxable value. */
    public static function fromExclusive(
        int $taxablePaise,
        string $gstPercent,
        string $cessPercent = '0',
        bool $interState = false,
    ): TaxBreakdown {
        $gstPaise = Money::percentOf($taxablePaise, (string) self::rate($gstPercent));
        $cessPaise = Money::percentOf($taxablePaise, (string) self::rate($cessPercent));

        return self::split($taxablePaise, $gstPaise, $cessPaise, $interState);
    }

    private static function split(int $taxable, int $gst, int $cess, bool $interState): TaxBreakdown
    {
        if ($interState) {
            return new TaxBreakdown($taxable, 0, 0, $gst, $cess);
        }

        // Halves must add up exactly; the odd paisa goes to SGST.
        $cgst = intdiv($gst, 2);

        return new TaxBreakdown($taxable, $cgst, $gst - $cgst, 0, $cess);
    }

    private static function rate(string $percent): BigDecimal
    {
        $rate = BigDecimal::of($percent);
        if ($rate->isNegative()) {
            throw new InvalidArgumentException("Tax rate cannot be negative: {$percent}");
        }

        return $rate;
    }
}
