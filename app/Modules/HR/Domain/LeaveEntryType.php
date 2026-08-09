<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * The signed-delta ledger entry kinds (docs/specs/05-hr-payroll.md 12.2).
 * Positive deltas add entitlement; `taken` is always negative.
 */
enum LeaveEntryType: string
{
    case Accrual = 'accrual';
    case Taken = 'taken';
    case Adjustment = 'adjustment';
    case Encashed = 'encashed';
    case CarriedForward = 'carried_forward';
    case Forfeited = 'forfeited';
    case Opening = 'opening';
}
