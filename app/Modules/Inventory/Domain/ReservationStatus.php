<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Domain;

/** docs/specs/06-assets-stores.md §7.7. */
enum ReservationStatus: string
{
    case Active = 'active';
    case Released = 'released';
    case Consumed = 'consumed';
}
