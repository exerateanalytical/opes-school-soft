<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.8 - why the supplier credited us.
 *
 * Named SupplierCreditNoteReasonType (not CreditNoteReasonType) to avoid
 * clashing with the Fees enum of that name - the procurement variant has
 * its own vocabulary (`return`, `cancellation`).
 */
enum SupplierCreditNoteReasonType: string
{
    case Return = 'return';
    case PriceCorrection = 'price_correction';
    case QuantityCorrection = 'quantity_correction';
    case Rebate = 'rebate';
    case Cancellation = 'cancellation';
}
