<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.7. Draft is recorded, approved is
 * SoD-signed-off (creator ≠ approver), paid is settled in the ledger and
 * IMMUTABLE, voided is reversed through `supplier_payment_voids` - never
 * an edit, never a delete.
 */
enum SupplierPaymentStatus: string
{
    case Draft = 'draft';
    case Approved = 'approved';
    case Paid = 'paid';
    case Voided = 'voided';
}
