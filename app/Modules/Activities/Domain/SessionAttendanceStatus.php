<?php

declare(strict_types=1);

namespace App\Modules\Activities\Domain;

/**
 * Per-session, per-member attendance. Deliberately its own three-state set
 * rather than a reuse of classroom attendance codes: an activity register
 * is a welfare fact ("who was at training"), not a statutory attendance
 * record, and coupling the two would drag activity registers into the
 * Attendance module's amendment rules.
 */
enum SessionAttendanceStatus: string
{
    case Present = 'present';
    case Absent = 'absent';
    case Excused = 'excused';
}
