<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 11.5: CNPS worker registration is due within
 * 8 days of hire; departure must be declared on termination.
 */
enum CnpsRegistrationStatus: string
{
    case NotRequired = 'not_required';
    case Pending = 'pending';
    case Registered = 'registered';
    case DeclaredDeparted = 'declared_departed';
}
