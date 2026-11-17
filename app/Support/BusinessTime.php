<?php

namespace App\Support;

use Carbon\CarbonImmutable;

/** Calendar operations use the property's local business day; persisted timestamps remain UTC. */
final class BusinessTime
{
    public static function timezone(): string
    {
        return (string) config('app.business_timezone', 'Asia/Manila');
    }

    public static function now(): CarbonImmutable
    {
        return CarbonImmutable::now(self::timezone());
    }

    public static function today(): CarbonImmutable
    {
        return self::now()->startOfDay();
    }

    public static function date(): string
    {
        return self::today()->toDateString();
    }

    public static function period(): string
    {
        return self::now()->format('Y-m');
    }
}
