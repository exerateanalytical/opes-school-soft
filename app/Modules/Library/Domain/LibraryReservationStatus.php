<?php

declare(strict_types=1);

namespace App\Modules\Library\Domain;

/** docs/specs/06-assets-stores.md §10.4. */
enum LibraryReservationStatus: string
{
    case Waiting = 'waiting';
    case Ready = 'ready';
    case Fulfilled = 'fulfilled';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
}
