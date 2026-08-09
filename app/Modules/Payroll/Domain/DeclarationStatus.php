<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * docs/specs/05-hr-payroll.md 11.1.
 */
enum DeclarationStatus: string
{
    case NotDue = 'not_due';
    case Due = 'due';
    case Generated = 'generated';
    case Filed = 'filed';
    case Paid = 'paid';
    case Late = 'late';
    case Rejected = 'rejected';
}
