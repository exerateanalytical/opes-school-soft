<?php

declare(strict_types=1);

namespace App\Support\Clock;

use DateTimeInterface;
use Illuminate\Support\Carbon;

/**
 * The single source of "what day is it" for attendance registers, cash-desk
 * close and daily collections.
 *
 * Cameroon is UTC+1 with no DST. Between 00:00 and 01:00 local,
 * now()->toDateString() in UTC returns YESTERDAY - which would file a payment
 * taken at 00:30 into the previous day's cash book
 * (docs/specs/00-core.md 7.5).
 */
final class BusinessDate
{
    public const TIMEZONE = 'Africa/Douala';

    public static function timezone(): string
    {
        return self::TIMEZONE;
    }

    /** Y-m-d in the business timezone. */
    public static function today(): string
    {
        return Carbon::now(self::TIMEZONE)->toDateString();
    }

    public static function from(DateTimeInterface $instant): string
    {
        return Carbon::instance($instant)->setTimezone(self::TIMEZONE)->toDateString();
    }

    public static function startOfToday(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->startOfDay();
    }

    public static function endOfToday(): Carbon
    {
        return Carbon::now(self::TIMEZONE)->endOfDay();
    }
}
