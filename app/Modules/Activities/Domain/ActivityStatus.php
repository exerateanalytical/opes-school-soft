<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * An activity is either running or closed. Closing is one-way in the MVP
 * (CloseActivity refuses a second close; there is no reopen Action), so a
 * closed activity is stable history - its memberships were ended in the
 * same transaction that closed it.
 */
enum ActivityStatus: string
{
    case Active = 'active';
    case Closed = 'closed';
}
