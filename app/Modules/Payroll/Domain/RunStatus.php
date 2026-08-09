<?php

declare(strict_types=1);

namespace App\Modules\Payroll\Domain;

/**
 * Payroll run lifecycle (docs/specs/05-hr-payroll.md 8.7). Every transition
 * is a conditional UPDATE with an affected-rows check, never read-then-write
 * (00-core 10.4); `cancelled` is reached only through a reversal run.
 */
enum RunStatus: string
{
    case Draft = 'draft';
    case Calculating = 'calculating';
    case Calculated = 'calculated';
    case Approved = 'approved';
    case Paid = 'paid';
    case Closed = 'closed';
    case Cancelled = 'cancelled';
}
