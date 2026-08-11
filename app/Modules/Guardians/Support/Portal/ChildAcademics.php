<?php

declare(strict_types=1);

namespace App\Modules\Guardians\Support\Portal;

use App\Modules\Guardians\Support\PortalContext;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Attendance (7.5 rows 11-12) and the class timetable (row 26), read once for
 * both doors - the /portal Livewire screens and the mobile API.
 *
 * Extracted from Guardians\Http\Api\AcademicsController when the web portal
 * gained the same screens, for the reason the build plan states as its second
 * hard constraint: one implementation per rule. Two copies of "which sessions
 * may a guardian see" would eventually answer differently, and the weaker copy
 * would become the product's real boundary.
 *
 * This class decides NOTHING. The caller has already asked GuardianPortalPolicy
 * whether the guardian holds row 11, row 12 or row 26; these methods only
 * assemble the rows that answer allows.
 */
final class ChildAcademics
{
    /**
     * Period summaries (row 11): the aggregate a dashboard shows.
     *
     * Returns a Collection, like every other reader in this namespace, so the
     * two callers can each take what they need - `->all()` for a JSON payload,
     * `->isEmpty()` for a Blade guard.
     *
     * @return Collection<int, \stdClass>
     */
    public function attendanceSummaries(int $studentId): Collection
    {
        $enrollmentIds = $this->enrollmentIds($studentId);

        if ($enrollmentIds === [] || ! Schema::hasTable('attendance_summaries')) {
            return collect();
        }

        return DB::table('attendance_summaries as s')
            ->join('assessment_periods as ap', 'ap.id', '=', 's.assessment_period_id')
            ->whereIn('s.enrollment_id', $enrollmentIds)
            ->orderByDesc('ap.starts_on')
            ->get([
                'ap.name as period_name', 'ap.name_fr as period_name_fr',
                's.sessions_expected', 's.sessions_present', 's.sessions_absent',
                's.sessions_excused', 's.sessions_late', 's.retards',
                's.hours_absent_justified', 's.hours_absent_unjustified', 's.computed_at',
            ])->values();
    }

    /**
     * Per-session records (row 12): every session with its justification state.
     * Capped, because a full year is thousands of rows and no parent reads
     * past the last term.
     *
     * @return Collection<int, \stdClass>
     */
    public function attendanceRecords(int $studentId, int $limit = 200): Collection
    {
        $enrollmentIds = $this->enrollmentIds($studentId);

        if ($enrollmentIds === [] || ! Schema::hasTable('attendance_records')) {
            return collect();
        }

        return DB::table('attendance_records as r')
            ->join('attendance_registers as reg', 'reg.id', '=', 'r.attendance_register_id')
            ->whereIn('r.enrollment_id', $enrollmentIds)
            ->orderByDesc('reg.date')
            ->limit($limit)
            ->get([
                'reg.date as session_date', 'reg.session', 'r.status', 'r.is_justified',
                'r.justification_type', 'r.minutes_late', 'r.remark',
            ])->values();
    }

    /**
     * The child's CLASS timetable (row 26), effective on the request's
     * business date.
     *
     * The date comes from PortalContext, not from the clock: 7.3 fixes it once
     * per request so a page loaded across midnight cannot show two different
     * days in two different panels.
     *
     * @return Collection<int, \stdClass>
     */
    public function timetable(int $studentId, ?string $asOf = null): Collection
    {
        // `current()` returns ?self, but PHPStan resolves the container binding
        // as non-null here; the explicit null check keeps both honest.
        $context = PortalContext::current();
        $asOf ??= $context === null ? now()->toDateString() : $context->asOf;

        $classGroupId = DB::table('enrollment_segments as seg')
            ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
            ->where('enr.student_id', $studentId)
            ->whereNull('seg.ends_on')
            ->whereIn('enr.status', ['pending', 'active', 'suspended'])
            ->orderByDesc('seg.starts_on')
            ->value('seg.class_group_id');

        if ($classGroupId === null || ! Schema::hasTable('timetable_slots')) {
            return collect();
        }

        return DB::table('timetable_slots as ts')
            ->join('timetable_periods as tp', 'tp.id', '=', 'ts.timetable_period_id')
            ->leftJoin('subjects as sub', 'sub.id', '=', 'ts.subject_id')
            ->leftJoin('rooms as r', 'r.id', '=', 'ts.room_id')
            ->where('ts.class_group_id', $classGroupId)
            ->where('ts.effective_from', '<=', $asOf)
            ->where(function ($query) use ($asOf): void {
                $query->whereNull('ts.effective_to')->orWhere('ts.effective_to', '>=', $asOf);
            })
            ->orderBy('ts.day_of_week')
            ->orderBy('tp.starts_at')
            ->get([
                'ts.day_of_week', 'tp.name as period_name', 'tp.starts_at', 'tp.ends_at',
                'sub.name as subject_name', 'r.name as room_name',
            ])->values();
    }

    /**
     * @return list<int>
     */
    private function enrollmentIds(int $studentId): array
    {
        return array_values(array_map(
            static fn ($id): int => (int) $id,
            DB::table('enrollments')->where('student_id', $studentId)->pluck('id')->all(),
        ));
    }
}
