<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/**
 * docs/specs/06-assets-stores.md §10.5/§10.7. Transitions after `invoiced`
 * are driven by events from Fees - the library never maintains its own
 * paid/unpaid flag; the screen reads through invoice_id.
 */
enum FineStatus: string
{
    case Assessed = 'assessed';
    case Invoiced = 'invoiced';
    case Paid = 'paid';
    case Waived = 'waived';
    case WrittenOff = 'written_off';
}
