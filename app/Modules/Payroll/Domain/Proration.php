<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Partial-month proration (docs/specs/05-hr-payroll.md 8.5):
 *
 *   prorated = round_half_up( full x days_worked / days_in_period )
 *
 * applied ONLY to components with `is_prorated = TRUE`. Which convention
 * produces `days_in_period` (calendar days, 30-day month, working days) is
 * CONFIGURATION on EmployerProfile - it ships NULL and blocks any partial
 * month at preflight; this class only executes the arithmetic, exactly,
 * with the chain's single rounding at the end.
 */
final class Proration
{
    public static function prorate(int $fullAmount, Rational $daysWorked, Rational $daysInPeriod): int
    {
        // A full period is the identity - no arithmetic, no rounding.
        if ($daysWorked->compare($daysInPeriod) === 0) {
            return $fullAmount;
        }

        return Rational::ofInt($fullAmount)
            ->times($daysWorked)
            ->dividedBy($daysInPeriod)
            ->roundHalfUp();
    }

    private function __construct()
    {
    }
}
