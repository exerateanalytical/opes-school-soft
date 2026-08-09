<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use RuntimeException;

/**
 * More than one verified statutory rate row matches the payroll period end
 * (docs/specs/05-hr-payroll.md 4.3). FATAL to the run: two candidate rates
 * means the configuration overlaps, and picking either would make the
 * payslip a coin toss. The overlap must be repaired by closing one row.
 */
final class StatutoryRateAmbiguous extends RuntimeException
{
    public static function for(string $code, string $periodEnd): self
    {
        return new self(
            "More than one verified statutory rate matches [{$code}] at period end {$periodEnd}."
            .' Close the overlapping row (effective_to is exclusive) - resolution must find exactly one.'
        );
    }
}
