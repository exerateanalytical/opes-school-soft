<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Welfare\Domain\InsurancePermission;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W5): "active enrollments minus active
 * student_insurances for the year". The design doc's reason for the policy
 * table to exist: the list an administrator chases before a school trip.
 *
 * Pure READ door over DB::table joins (ModuleBoundaryTest) - cover is
 * DERIVED from certificate rows, never stored on the enrollment. Only
 * ACTIVE certificates under ACTIVE student-cover policies of the SAME
 * academic year count; a lapsed certificate or a cancelled policy leaves
 * the student uninsured. Scoping to one policy answers "who is missing
 * from THIS policy" for a school running several.
 */
final class UninsuredStudentsReport
{
    /**
     * @return list<array{
     *     enrollment_id: int,
     *     student_id: int,
     *     matricule: string,
     *     first_name: string,
     *     last_name: string,
     *     class_level: string|null,
     * }>
     */
    public function handle(int $academicYearId, ?int $policyId = null): array
    {
        Gate::authorize(InsurancePermission::VIEW);

        $rows = DB::table('enrollments as e')
            ->join('students as s', 's.id', '=', 'e.student_id')
            ->leftJoin('class_levels as cl', 'cl.id', '=', 'e.class_level_id')
            ->where('e.academic_year_id', $academicYearId)
            ->where('e.status', 'active')
            ->whereNotExists(function ($query) use ($policyId): void {
                $query->select(DB::raw(1))
                    ->from('student_insurances as si')
                    ->join('insurance_policies as p', 'p.id', '=', 'si.policy_id')
                    ->whereColumn('si.enrollment_id', 'e.id')
                    ->where('si.status', 'active')
                    ->where('p.cover_type', 'student')
                    ->where('p.status', 'active')
                    ->whereColumn('p.academic_year_id', 'e.academic_year_id')
                    ->when($policyId !== null, fn ($q) => $q->where('p.id', $policyId));
            })
            ->orderBy('s.last_name')
            ->orderBy('s.first_name')
            ->get(['e.id as enrollment_id', 's.id as student_id', 's.matricule', 's.first_name', 's.last_name', 'cl.name as class_level']);

        $report = [];

        foreach ($rows as $row) {
            /** @var object{enrollment_id: int|string, student_id: int|string, matricule: string, first_name: string, last_name: string, class_level: string|null} $row */
            $report[] = [
                'enrollment_id' => (int) $row->enrollment_id,
                'student_id' => (int) $row->student_id,
                'matricule' => $row->matricule,
                'first_name' => $row->first_name,
                'last_name' => $row->last_name,
                'class_level' => $row->class_level,
            ];
        }

        return $report;
    }
}
