<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 12.2.
 */
enum LeaveRequestStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';
    case Taken = 'taken';
}
