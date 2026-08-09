<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.2. Approval is the immutability
 * boundary: from `approved` onwards the line set changes only through a
 * PurchaseOrderAmendment (invariant 5).
 */
enum PurchaseOrderStatus: string
{
    case Draft = 'draft';
    case PendingApproval = 'pending_approval';
    case Approved = 'approved';
    case Sent = 'sent';
    case PartiallyReceived = 'partially_received';
    case Received = 'received';
    case PartiallyInvoiced = 'partially_invoiced';
    case Invoiced = 'invoiced';
    case Closed = 'closed';
    case Cancelled = 'cancelled';

    /** Before approval the document is freely editable. */
    public function isPreApproval(): bool
    {
        return $this === self::Draft || $this === self::PendingApproval;
    }

    /** The states a goods receipt may be recorded against. */
    public function isReceivable(): bool
    {
        return in_array($this, [self::Approved, self::Sent, self::PartiallyReceived], true);
    }
}
