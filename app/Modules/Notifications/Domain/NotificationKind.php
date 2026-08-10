<?php

declare(strict_types=1);

namespace App\Modules\Notifications\Domain;

/**
 * What triggered a notification, used for filtering and iconography in the
 * bell dropdown. `unfinished_work` is the kind ScheduleUnfinishedWorkSweep
 * emits for a held draft nobody has returned to.
 */
enum NotificationKind: string
{
    case UnfinishedWork = 'unfinished_work';
    case Message = 'message';
    case System = 'system';
    case Workflow = 'workflow';
}
