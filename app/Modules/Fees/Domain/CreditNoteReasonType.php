<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §9. A credit note says "we billed the wrong amount";
 * a FeeAdjustment says "we billed correctly and are granting a reduction".
 */
enum CreditNoteReasonType: string
{
    case OverInvoiced = 'over_invoiced';
    case ServiceNotDelivered = 'service_not_delivered';
    case Withdrawal = 'withdrawal';
    case DuplicateInvoice = 'duplicate_invoice';
    case PriceCorrection = 'price_correction';
    case Goodwill = 'goodwill';
}
