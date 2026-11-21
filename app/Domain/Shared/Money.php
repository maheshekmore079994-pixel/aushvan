<?php

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\BigInteger;
use Brick\Math\Exception\MathException;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Money is always an integer number of paise. This class holds the only
 * conversions and rounding rules; nothing else may use floats for money.
 */
final class Money
{
    /** "350", "350.5", "350.50" -> 35050. More than two decimals is rejected, never silently rounded. */
    public static function parseRupees(string|int $rupees): int
    {
        try {
            $value = BigDecimal::of(is_string($rupees) ? trim($rupees) : $rupees);
        } catch (MathException) {
            throw new InvalidArgumentException("Not a valid amount: {$rupees}");
        }

        $value = $value->strippedOfTrailingZeros();
        if ($value->getScale() > 2) {
            throw new InvalidArgumentException("Amount has more than two decimals: {$rupees}");
        }

        return $value->multipliedBy(100)->toBigInteger()->toInt();
    }

    /** 12345650 -> "1,23,456.50" (Indian digit grouping, no currency sign). */
    public static function format(int $paise): string
    {
        $negative = $paise < 0;
        $abs = abs($paise);
        $rupees = (string) intdiv($abs, 100);
        $fraction = str_pad((string) ($abs % 100), 2, '0', STR_PAD_LEFT);

        if (strlen($rupees) > 3) {
            $last3 = substr($rupees, -3);
            $rest = substr($rupees, 0, -3);
            $rest = ltrim(strrev(implode(',', str_split(strrev($rest), 2))), ',');
            $rupees = $rest.','.$last3;
        }

        return ($negative ? '-' : '').$rupees.'.'.$fraction;
    }

    /**
     * a × b ÷ divisor, rounded half up (away from zero) to a whole number.
     * Used for qty × price ÷ unit factor and similar.
     */
    public static function mulDiv(int $a, int $b, int $divisor): int
    {
        if ($divisor === 0) {
            throw new InvalidArgumentException('Division by zero');
        }

        return BigInteger::of($a)
            ->multipliedBy($b)
            ->toBigDecimal()
            ->dividedBy($divisor, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /** amount × percent ÷ 100, rounded half up. Percent is a decimal string such as "2.5". */
    public static function percentOf(int $paise, string $percent): int
    {
        return BigDecimal::of($paise)
            ->multipliedBy(BigDecimal::of($percent))
            ->dividedBy(100, 0, RoundingMode::HalfUp)
            ->toInt();
    }

    /** Difference needed to bring an amount to the nearest whole rupee (50 paise rounds up). */
    public static function roundOffToRupee(int $paise): int
    {
        return self::mulDiv($paise, 1, 100) * 100 - $paise;
    }
}
