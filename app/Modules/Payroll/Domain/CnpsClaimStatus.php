<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * docs/specs/05-hr-payroll.md 11.6. An outstanding claim is a RECEIVABLE
 * and ages on the receivables report like any other.
 */
enum CnpsClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case PartReimbursed = 'part_reimbursed';
    case Reimbursed = 'reimbursed';
    case Rejected = 'rejected';
}
