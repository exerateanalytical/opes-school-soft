<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.5. The lifecycle of the one document
 * in the P2P chain that is NEVER optional - it creates the payable and the
 * expense.
 *
 * `match_exception` blocks approval until an override (§4.4) or a document
 * correction; `posted` makes the invoice immutable except for the payment
 * columns; `partially_paid`/`paid` are advanced by F4's allocation path.
 */
enum SupplierInvoiceStatus: string
{
    case Draft = 'draft';
    case PendingMatch = 'pending_match';
    case MatchException = 'match_exception';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Posted = 'posted';
    case PartiallyPaid = 'partially_paid';
    case Paid = 'paid';
    case Cancelled = 'cancelled';
    case Disputed = 'disputed';

    /** The states in which the commercial substance may still change. */
    public function isEditable(): bool
    {
        return in_array($this, [self::Draft, self::PendingMatch, self::MatchException], true);
    }
}
