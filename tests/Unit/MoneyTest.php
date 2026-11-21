<?php

namespace Tests\Unit;

use App\Domain\Shared\Money;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    #[DataProvider('rupeeInputs')]
    public function test_parses_rupees_to_paise(string|int $input, int $paise): void
    {
        $this->assertSame($paise, Money::parseRupees($input));
    }

    public static function rupeeInputs(): array
    {
        return [
            ['350', 35000],
            ['350.5', 35050],
            ['350.50', 35050],
            [' 0.05 ', 5],
            ['350.500', 35050],
            [42, 4200],
            ['-12.34', -1234],
            ['0.29', 29], // 0.29 * 100 is 28.999… in floating point
        ];
    }

    public function test_rejects_more_than_two_decimals(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseRupees('10.005');
    }

    public function test_rejects_text(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::parseRupees('ten');
    }

    public function test_formats_with_indian_grouping(): void
    {
        $this->assertSame('0.05', Money::format(5));
        $this->assertSame('350.00', Money::format(35000));
        $this->assertSame('1,234.50', Money::format(123450));
        $this->assertSame('1,23,456.00', Money::format(12345600));
        $this->assertSame('12,34,56,789.01', Money::format(12345678901));
        $this->assertSame('-1,00,000.00', Money::format(-10000000));
    }

    public function test_mul_div_rounds_half_up(): void
    {
        // 3 tablets from a strip of 10 priced ₹35.00
        $this->assertSame(1050, Money::mulDiv(3, 3500, 10));
        // 250 g at ₹42.00 per kg
        $this->assertSame(1050, Money::mulDiv(250, 4200, 1000));
        // 1 piece from a box of 12 costing ₹100.00 = 833.33… paise
        $this->assertSame(833, Money::mulDiv(1, 10000, 12));
        // exactly half rounds up
        $this->assertSame(3, Money::mulDiv(5, 1, 2));
        $this->assertSame(-3, Money::mulDiv(-5, 1, 2));
    }

    public function test_percent_of(): void
    {
        $this->assertSame(250, Money::percentOf(10000, '2.5'));
        $this->assertSame(1, Money::percentOf(10, '5')); // 0.5 paise rounds up
        $this->assertSame(0, Money::percentOf(10000, '0'));
    }

    public function test_round_off_to_rupee(): void
    {
        $this->assertSame(0, Money::roundOffToRupee(35000));
        $this->assertSame(-49, Money::roundOffToRupee(35049));
        $this->assertSame(50, Money::roundOffToRupee(35050));
        $this->assertSame(1, Money::roundOffToRupee(35099));
    }
}
