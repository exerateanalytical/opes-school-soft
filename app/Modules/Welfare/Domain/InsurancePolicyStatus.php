<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Lifecycle of a policy contract with the insurer.
 *
 *  - Active: cover is (or will be) in force; enrolment and claims allowed.
 *  - Expired: the coverage period ran out - history, still claimable-looking
 *    rows are read-only.
 *  - Cancelled: terminated before its end date (10.5 archive path: the row
 *    and its certificates stay forever).
 */
enum InsurancePolicyStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
