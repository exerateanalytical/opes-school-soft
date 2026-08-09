<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions;

use App\Modules\Attendance\Models\AttendanceSummary;

/**
 * READ DOOR (docs/plans/phase-08.md F2): the report card and the student
 * profile read the persisted per-period rollup through this Action, never
 * the AttendanceSummary model — the answer crosses the module boundary as a
 * plain array. NULL rate means "not recorded", rendered "—", never 0% (C5).
 *
 * Deliberately not Gate-checked: consumed inside callers that carry their
 * own authorization (the ResolveCalendarDay pattern).
 */
final class GetAttendanceSummary
{
    /**
     * @return array{
     *     enrollment_id: int,
     *     assessment_period_id: int,
     *     sessions_expected: int,
     *     sessions_present: int,
     *     sessions_absent: int,
     *     sessions_excused: int,
     *     sessions_late: int,
     *     sessions_suspended: int,
     *     hours_absent_justified: float,
     *     hours_absent_unjustified: float,
     *     retards: int,
     *     attendance_rate: float|null,
     *     computed_at: string,
     * }|null null when no summary has ever been computed for the pair.
     */
    public function handle(int $enrollmentId, int $assessmentPeriodId): ?array
    {
        $summary = AttendanceSummary::query()
            ->where('enrollment_id', $enrollmentId)
            ->where('assessment_period_id', $assessmentPeriodId)
            ->first();

        if ($summary === null) {
            return null;
        }

        return [
            'enrollment_id' => $summary->enrollment_id,
            'assessment_period_id' => $summary->assessment_period_id,
            'sessions_expected' => $summary->sessions_expected,
            'sessions_present' => $summary->sessions_present,
            'sessions_absent' => $summary->sessions_absent,
            'sessions_excused' => $summary->sessions_excused,
            'sessions_late' => $summary->sessions_late,
            'sessions_suspended' => $summary->sessions_suspended,
            'hours_absent_justified' => (float) $summary->hours_absent_justified,
            'hours_absent_unjustified' => (float) $summary->hours_absent_unjustified,
            'retards' => $summary->retards,
            'attendance_rate' => $summary->attendanceRate(),
            'computed_at' => $summary->computed_at->toIso8601String(),
        ];
    }
}
