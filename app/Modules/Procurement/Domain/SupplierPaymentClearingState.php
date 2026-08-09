<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Domain;

/**
 * docs/specs/03-tax-procurement.md §4.7 - cheques mirror the 04-fees
 * clearing model, posting to *effets à payer* while pending. The v1
 * methods (cash / mobile money / bank transfer) are immediate instruments:
 * a paid payment is `cleared`; `not_applicable` is the state before the
 * money moves.
 */
enum SupplierPaymentClearingState: string
{
    case NotApplicable = 'not_applicable';
    case Pending = 'pending';
    case Cleared = 'cleared';
    case Bounced = 'bounced';
}
