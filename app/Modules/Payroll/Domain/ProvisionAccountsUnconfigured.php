<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

use DomainException;

/**
 * docs/specs/05-hr-payroll.md 12.5: the leave-provision account codes
 * (66x / 428x) are NEEDS VERIFICATION. Until the accountant maps them onto
 * the ALLOCATION_CONGE component, the provision CALCULATES AND REPORTS but
 * does not post - this exception carries the computed report so the caller
 * can still surface the number.
 */
final class ProvisionAccountsUnconfigured extends DomainException
{
    /**
     * @param  array<string, mixed>  $report
     */
    public function __construct(public readonly array $report, string $message)
    {
        parent::__construct($message);
    }
}
