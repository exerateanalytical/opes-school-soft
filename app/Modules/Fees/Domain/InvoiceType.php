<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §3. Supplementary invoices (§4.6) carry
 * `fee_structure_id = NULL` and are exempted from the issue-idempotency
 * UNIQUE by the generated-column key that only covers `standard` rows.
 */
enum InvoiceType: string
{
    case Standard = 'standard';
    case Supplementary = 'supplementary';
    case OpeningBalance = 'opening_balance';
}
