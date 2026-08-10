<?php

declare(strict_types=1);

namespace App\Modules\Forms\Domain;

/**
 * `draft` is a silent, self-overwriting autosave - the user never asked for
 * it and it carries no weight beyond "resume where I left off" on the SAME
 * device/session flow. `held` is deliberate: the user clicked Hold, it now
 * appears on their "unfinished work" list, and it can trigger a
 * notification if left untouched (ScheduleUnfinishedWorkSweep).
 */
enum DraftStatus: string
{
    case Draft = 'draft';
    case Held = 'held';
}
