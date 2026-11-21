<?php

namespace App\Domain\Shared;

use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * A rate is "paise per base unit" kept to six decimals as a string, so that
 * Rs 100 for a box of 12 (833.333333 paise each) does not lose money when
 * multiplied back. Rates are never added up in SQL and never held as floats.
 */
final class Rate
{
    public const SCALE = 6;

    /** Price of one pack in paise, divided by the base units in the pack. */
    public static function perBase(int $paisePerPack, int $baseUnitsInPack): string
    {
        if ($baseUnitsInPack < 1) {
            throw new InvalidArgumentException('A pack must hold at least one base unit.');
        }

        return (string) BigDecimal::of($paisePerPack)->dividedBy($baseUnitsInPack, self::SCALE, RoundingMode::HalfUp);
    }

    /** rate x quantity, rounded half up to whole paise. */
    public static function amount(string $rate, int $qtyBase): int
    {
        return BigDecimal::of($rate)->multipliedBy($qtyBase)->toScale(0, RoundingMode::HalfUp)->toInt();
    }
}
