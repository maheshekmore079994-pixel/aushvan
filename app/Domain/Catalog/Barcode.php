<?php

namespace App\Domain\Catalog;

/** Shop-made barcodes for products that have none, in the EAN-13 range reserved for in-store use. */
final class Barcode
{
    /** 20 + ten-digit number + check digit. */
    public static function internal(int $number): string
    {
        $body = '20'.str_pad((string) $number, 10, '0', STR_PAD_LEFT);

        return $body.self::checkDigit($body);
    }

    public static function checkDigit(string $twelveDigits): int
    {
        $sum = 0;
        foreach (str_split($twelveDigits) as $index => $digit) {
            $sum += (int) $digit * ($index % 2 === 0 ? 1 : 3);
        }

        return (10 - $sum % 10) % 10;
    }
}
