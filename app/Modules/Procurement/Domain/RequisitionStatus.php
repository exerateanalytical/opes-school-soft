<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.1. Deletion exists only for `draft`
 * (BEFORE DELETE trigger, §9); everything after submission is cancelled or
 * rejected, never deleted.
 */
enum RequisitionStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Approved = 'approved';
    case PartiallyOrdered = 'partially_ordered';
    case Ordered = 'ordered';
    case Rejected = 'rejected';
    case Cancelled = 'cancelled';

    /** The states a PO may still be raised against (§4.2). */
    public function isOrderable(): bool
    {
        return $this === self::Approved || $this === self::PartiallyOrdered;
    }
}
