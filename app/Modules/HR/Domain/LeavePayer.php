<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * Who pays the staff member while on this leave type
 * (docs/specs/05-hr-payroll.md 12.2). CNPS-paid leave (maternity) is
 * ADVANCED by the employer and reclaimed via CnpsBenefitClaim (11.6).
 */
enum LeavePayer: string
{
    case Employer = 'employer';
    case Cnps = 'cnps';
    case Unpaid = 'unpaid';
}
