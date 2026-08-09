<?php

declare(strict_types=1);

namespace App\Modules\HR\Domain;

/**
 * Lifecycle shared by teaching-hours logs and timesheets
 * (docs/specs/05-hr-payroll.md 5.5). `hours_planned`/`hours_taught` are
 * proposals; only a fully `validated` month ever reaches payroll - no
 * partial inclusion, no "assume planned".
 */
enum TimesheetStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Validated = 'validated';
    case Rejected = 'rejected';
}
