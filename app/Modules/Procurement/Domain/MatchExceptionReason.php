<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.4 - the TYPED reason a line failed
 * its match. Outside tolerance ⇒ `match_exception`, blocking approval
 * until overridden (`procurement.invoice_override_match`, reason recorded)
 * or the underlying documents are corrected.
 */
enum MatchExceptionReason: string
{
    case PriceVariance = 'price_variance';
    case QuantityVariance = 'quantity_variance';
    case NoReceipt = 'no_receipt';
    case OverInvoiced = 'over_invoiced';
    case NoPo = 'no_po';
    case SupplierMismatch = 'supplier_mismatch';
}
