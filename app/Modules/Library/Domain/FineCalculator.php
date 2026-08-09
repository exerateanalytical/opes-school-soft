<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §10.5 - the overdue-fine ENTITLEMENT.
 *
 * Pure function of scalars (no Eloquent): given the due date, the as-of
 * date, the membership class terms and the set of library-closed dates,
 * it answers "what should this fine be, in total, today". The nightly
 * accrual RECOMPUTES this entitlement and adjusts - exactly like the
 * depreciation catch-up (§4.3) - rather than adding a day each night, so
 * a job that runs twice, or is missed for a week, still lands on the
 * correct figure (acceptance 11).
 */
final class FineCalculator
{
    private function __construct() {}

    /**
     * Days overdue: calendar days in (due_on, as_of], excluding days the
     * library was closed per the school calendar.
     *
     * @param  list<string>  $closedDates  Y-m-d dates the library was closed
     */
    public static function daysOverdue(string $dueOn, string $asOf, array $closedDates = []): int
    {
        $due = Carbon::parse($dueOn)->startOfDay();
        $end = Carbon::parse($asOf)->startOfDay();

        if ($end->lessThanOrEqualTo($due)) {
            return 0;
        }

        $closed = array_fill_keys($closedDates, true);
        $days = 0;
        $cursor = $due->copy()->addDay();

        while ($cursor->lessThanOrEqualTo($end)) {
            if (! isset($closed[$cursor->toDateString()])) {
                $days++;
            }

            $cursor->addDay();
        }

        return $days;
    }

    /**
     * The full entitlement: `max(0, days_overdue − grace_days) ×
     * fine_per_day`, capped at the book's replacement cost when the
     * membership class says so (a 200 FCFA/day fine on a 6,000 FCFA book
     * must not reach 40,000).
     *
     * @param  list<string>  $closedDates
     * @return array{days_overdue: int, amount: int}
     */
    public static function overdueEntitlement(
        string $dueOn,
        string $asOf,
        int $graceDays,
        int $finePerDay,
        FineCapPolicy $capPolicy,
        int $replacementCost,
        array $closedDates = [],
    ): array {
        $daysOverdue = self::daysOverdue($dueOn, $asOf, $closedDates);

        $amount = max(0, $daysOverdue - $graceDays) * $finePerDay;

        if ($capPolicy === FineCapPolicy::ReplacementCost && $replacementCost > 0) {
            $amount = min($amount, $replacementCost);
        }

        return ['days_overdue' => $daysOverdue, 'amount' => $amount];
    }
}
