<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * docs/specs/05-hr-payroll.md 13.1.
 */
enum SettlementStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
}
