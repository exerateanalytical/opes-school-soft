<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * docs/specs/05-hr-payroll.md 8.8. A failed line does not fail the payment:
 * it moves to `partially_failed` and the failed lines are re-exportable.
 */
enum PayrollPaymentStatus: string
{
    case Prepared = 'prepared';
    case Exported = 'exported';
    case Confirmed = 'confirmed';
    case PartiallyFailed = 'partially_failed';
}
