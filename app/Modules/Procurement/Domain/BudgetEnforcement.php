<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.1 - what requisition approval does
 * when the estimated spend exceeds (or cannot be checked against) the
 * budget line. Commitment accounting is explicitly out of scope for v2:
 * consumption is measured at read time, never carried as a ledger
 * commitment.
 */
enum BudgetEnforcement: string
{
    case None = 'none';
    case Warn = 'warn';
    case Block = 'block';
}
