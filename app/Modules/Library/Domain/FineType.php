<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.5. */
enum FineType: string
{
    case Overdue = 'overdue';
    case Damage = 'damage';
    case Loss = 'loss';
    case Other = 'other';
}
