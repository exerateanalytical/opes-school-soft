<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.8. */
enum AcquisitionSource: string
{
    case Purchase = 'purchase';
    case Donation = 'donation';
    case Transfer = 'transfer';
}
