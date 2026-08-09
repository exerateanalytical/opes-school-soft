<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.2. */
enum BookCopyStatus: string
{
    case Available = 'available';
    case Issued = 'issued';
    case Reserved = 'reserved';
    case Lost = 'lost';
    case Damaged = 'damaged';
    case Withdrawn = 'withdrawn';
    case InRepair = 'in_repair';
}
