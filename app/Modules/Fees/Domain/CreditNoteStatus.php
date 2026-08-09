<?php

declare(strict_types=1);

namespace App\Modules\Fees\Domain;

/** docs/specs/04-fees.md §9 - a facture d'avoir has its own lifecycle. */
enum CreditNoteStatus: string
{
    case Draft = 'draft';
    case Issued = 'issued';
    case Cancelled = 'cancelled';
}
