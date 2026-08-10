<?php

declare(strict_types=1);

namespace App\Modules\Accounting\Domain;

/**
 * docs/specs/02-accounting.md §17.3 - `YearEndChecklist.status`.
 * `completed` means every mandatory item is completed or waived, which is
 * the YE-1 precondition for moving the fiscal year to `closed`.
 */
enum YearEndChecklistStatus: string
{
    case NotStarted = 'not_started';
    case InProgress = 'in_progress';
    case Completed = 'completed';
}
