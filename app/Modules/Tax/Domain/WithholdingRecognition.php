<?php

declare(strict_types=1);

namespace App\Modules\Tax\Domain;

/**
 * docs/specs/03-tax-procurement.md §6.3 / §12 item 15 - whether withholding
 * is legally recognised on invoice or on payment. NEEDS VERIFICATION, so
 * TaxSettings.withholding_recognition ships NULL and blocking. The choice
 * drives which date (invoice vs payment) selects the WithholdingRule
 * version.
 */
enum WithholdingRecognition: string
{
    case OnInvoice = 'on_invoice';
    case OnPayment = 'on_payment';
}
