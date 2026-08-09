<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Domain;

/**
 * Lifecycle of an insurance claim (phase-10 plan §3 row 13):
 * draft → submitted → settled | rejected. The schema CHECK forces a settled
 * claim to carry amount_settled + settled_on, a rejected one a decision
 * date, and an open one neither. The settlement CASH RECEIPT is a treasury
 * concern deferred past Phase 10 (tracked debt) - no ledger writes here.
 */
enum ClaimStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Settled = 'settled';
    case Rejected = 'rejected';

    /** May this claim still be decided (settled or rejected)? */
    public function isOpen(): bool
    {
        return $this === self::Draft || $this === self::Submitted;
    }
}
