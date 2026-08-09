<?php

declare(strict_types=1);

namespace App\Modules\Attendance\Actions\Concerns;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The §9.5 roster query (docs/specs/07-students.md), shared by open, submit
 * validation, amendment roster-correction and the summary/rate rebuilds.
 * Enrollments and their segments are Students-owned tables, read through the
 * query builder only (ModuleBoundaryTest — "No exceptions").
 */
trait ResolvesRoster
{
    /**
     * Who is expected in this class group on this date — §9.5 verbatim.
     * Segments are contiguous and gapless (07-students §5.2), so the roster
     * on any date is exactly one segment per enrollment. `expected_count`
     * is frozen on the header at open from this list's size.
     *
     * @return list<int> enrollment ids
     */
    private function rosterEnrollmentIds(int $classGroupId, string $date): array
    {
        /** @var list<int> */
        return DB::table('enrollments as e')
            ->join('enrollment_segments as s', 's.enrollment_id', '=', 'e.id')
            ->where('s.class_group_id', $classGroupId)
            ->whereDate('s.starts_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('s.ends_on')->orWhereDate('s.ends_on', '>=', $date);
            })
            // Suspended students COUNT in expected and are recorded with
            // status='suspended' — excluding them would let a suspension
            // inflate the class's attendance rate (§9.5).
            ->whereIn('e.status', ['active', 'suspended'])
            ->whereDate('e.enrolled_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('e.left_on')->orWhereDate('e.left_on', '>=', $date);
            })
            ->orderBy('e.id')
            ->pluck('e.id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }
}
