<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/**
 * docs/specs/04-fees.md §9 - whether the credit sits as student credit or
 * triggers a Refund (§12/§13, later work packages).
 */
enum CreditNoteSettlementMode: string
{
    case ApplyToAccount = 'apply_to_account';
    case Refund = 'refund';
}
