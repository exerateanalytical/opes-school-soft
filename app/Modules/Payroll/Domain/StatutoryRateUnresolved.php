<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use RuntimeException;

/**
 * No verified statutory rate row covers the payroll period end
 * (docs/specs/05-hr-payroll.md 4.3). FATAL to the run - there is no
 * fallback path and no default rate anywhere in the codebase. An empty
 * field stops a bursar for an afternoon; a wrong rate looks authoritative
 * on a payslip and the school pays the reassessment (05 §0).
 */
final class StatutoryRateUnresolved extends RuntimeException
{
    public static function for(string $code, string $periodEnd): self
    {
        return new self(
            "No verified statutory rate resolves for [{$code}] at period end {$periodEnd}."
            .' Configure the rate from the school\'s own notification letter or notice - payroll is blocked until then.'
        );
    }
}
