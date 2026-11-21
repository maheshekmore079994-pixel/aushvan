<?php

namespace App\Domain\Shared;

use Illuminate\Support\Carbon;

/** Business dates are in the shop's local time, whatever the server clock says. */
final class ShopClock
{
    public static function today(): Carbon
    {
        return Carbon::now(config('shop.timezone'))->startOfDay();
    }

    public static function todayString(): string
    {
        return self::today()->toDateString();
    }
}
