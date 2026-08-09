<?php

declare(strict_types=1);

namespace App\Modules\Assets\Domain;

/**
 * 06-assets-stores.md §2.4.
 */
enum MaintenanceStatus: string
{
    case Open = 'open';
    case Assigned = 'assigned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Cancelled = 'cancelled';

    public function isClosed(): bool
    {
        return in_array($this, [self::Done, self::Cancelled], true);
    }
}
