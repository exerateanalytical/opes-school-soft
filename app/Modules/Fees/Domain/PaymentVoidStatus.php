<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §11.5. A payment is voided when - and only when -
 * a `confirmed` void row exists; `Payment::isVoided()` derives from this,
 * there is no `payments.is_voided` column to drift.
 */
enum PaymentVoidStatus: string
{
    case PendingApproval = 'pending_approval';
    case Confirmed = 'confirmed';
    case Rejected = 'rejected';
}
