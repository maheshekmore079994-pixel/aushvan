<?php

namespace App\Domain\Shared;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/** Indian financial year, April to March, in the shop's local time. */
final class FinancialYear
{
    /** 11 Nov 2026 -> "26-27"; 31 Mar 2027 -> "26-27"; 1 Apr 2027 -> "27-28". */
    public static function codeFor(?CarbonInterface $moment = null): string
    {
        $local = ($moment ?? Carbon::now())->copy()->setTimezone(config('shop.timezone'));
        $startYear = $local->month >= 4 ? $local->year : $local->year - 1;

        return sprintf('%02d-%02d', $startYear % 100, ($startYear + 1) % 100);
    }
}
