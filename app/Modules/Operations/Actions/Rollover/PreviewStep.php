<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The per-step dry-run diff (docs/specs/08-operations.md §6.3 "Previewable"):
 * counts by entity, plus the row-level list when it stays under 200 rows.
 * Zero writes, ever - the wizard renders this before each step's Apply
 * button.
 *
 * Previews are available for any step at or after the run's current one; the
 * numbers for a future step are computed against the database as it stands,
 * so they sharpen as earlier steps apply.
 *
 * @phpstan-type Preview array{step: int, counts: array<string, int>, rows: list<array<string, mixed>>}
 */
final class PreviewStep
{
    private const ROW_LIST_CAP = 200;

    /**
     * @return Preview
     */
    public function handle(RolloverRun $run, RolloverStep $step): array
    {
        Gate::authorize(StartRolloverRun::PERMISSION);

        $fromYearId = $run->academic_year_from_id;
        $toYearId = $run->academic_year_to_id;

        return match ($step) {
            RolloverStep::Preflight => $this->preflight($run),
            RolloverStep::CreateNewYear => $this->newYear($run),
            RolloverStep::CopyClassGroups => $this->classGroups($fromYearId, $toYearId),
            RolloverStep::CopySubjectAllocations => $this->subjectAllocations($fromYearId, $toYearId),
            RolloverStep::CopyAssessmentPeriods => $this->assessmentPeriods($fromYearId, $toYearId),
            RolloverStep::CopyFeeStructures => $this->feeStructures($fromYearId, $toYearId),
            RolloverStep::PromoteStudents => $this->promotions($fromYearId),
            RolloverStep::CarryBalances => $this->balances($fromYearId),
            RolloverStep::ArchiveLeavers => $this->leavers($fromYearId),
            RolloverStep::ReassignTeachers => $this->teachers($toYearId),
            RolloverStep::FlipActiveYear => $this->flip($run),
        };
    }

    /**
     * @param  array<string, int>  $counts
     * @param  list<array<string, mixed>>  $rows
     * @return Preview
     */
    private function shape(RolloverStep $step, array $counts, array $rows): array
    {
        return [
            'step' => $step->value,
            'counts' => $counts,
            'rows' => count($rows) < self::ROW_LIST_CAP ? $rows : [],
        ];
    }

    /**
     * @return Preview
     */
    private function preflight(RolloverRun $run): array
    {
        $unpublished = (int) DB::table('assessment_periods')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('is_reporting_period', true)
            ->where('status', '!=', 'closed')
            ->count();

        $drafts = (int) DB::table('journal_entries')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('status', 'draft')
            ->count();

        return $this->shape(RolloverStep::Preflight, [
            'unpublished_reporting_periods' => $unpublished,
            'draft_journal_entries' => $drafts,
        ], []);
    }

    /**
     * @return Preview
     */
    private function newYear(RolloverRun $run): array
    {
        $from = RolloverStepMechanics::yearRow($run->academic_year_from_id);
        $starts = Carbon::parse((string) $from->ends_on)->addDay();
        $length = (int) Carbon::parse((string) $from->starts_on)->diffInDays(Carbon::parse((string) $from->ends_on));

        $exists = DB::table('academic_years')->whereDate('starts_on', $starts->toDateString())->exists();

        return $this->shape(RolloverStep::CreateNewYear, [
            'academic_years' => $exists ? 0 : 1,
        ], [[
            'starts_on' => $starts->toDateString(),
            'ends_on' => $starts->copy()->addDays($length)->toDateString(),
            'adopts_existing' => $exists,
        ]]);
    }

    /**
     * @return Preview
     */
    private function classGroups(int $fromYearId, ?int $toYearId): array
    {
        $rows = [];

        foreach (DB::table('class_groups')->where('academic_year_id', $fromYearId)->orderBy('id')->get() as $row) {
            $exists = $toYearId !== null && DB::table('class_groups')
                ->where('academic_year_id', $toYearId)
                ->where('class_level_id', (int) $row->class_level_id)
                ->where('name', (string) $row->name)
                ->exists();

            if (! $exists) {
                $rows[] = [
                    'name' => (string) $row->name,
                    'class_level_id' => (int) $row->class_level_id,
                    'capacity' => (int) $row->capacity,
                    'class_teacher_review' => $row->class_teacher_staff_id !== null,
                ];
            }
        }

        return $this->shape(RolloverStep::CopyClassGroups, ['class_groups' => count($rows)], $rows);
    }

    /**
     * @return Preview
     */
    private function subjectAllocations(int $fromYearId, ?int $toYearId): array
    {
        $rows = [];

        $source = DB::table('subject_allocations')
            ->where('academic_year_id', $fromYearId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($source as $row) {
            $exists = $toYearId !== null && DB::table('subject_allocations')
                ->where('academic_year_id', $toYearId)
                ->where('class_level_id', (int) $row->class_level_id)
                ->where('stream_id', (int) $row->stream_id)
                ->where('subject_id', (int) $row->subject_id)
                ->exists();

            if (! $exists) {
                $rows[] = [
                    'class_level_id' => (int) $row->class_level_id,
                    'stream_id' => (int) $row->stream_id,
                    'subject_id' => (int) $row->subject_id,
                    'coefficient' => (string) $row->coefficient,
                ];
            }
        }

        return $this->shape(RolloverStep::CopySubjectAllocations, ['subject_allocations' => count($rows)], $rows);
    }

    /**
     * @return Preview
     */
    private function assessmentPeriods(int $fromYearId, ?int $toYearId): array
    {
        $existingCodes = $toYearId === null
            ? []
            : DB::table('assessment_periods')->where('academic_year_id', $toYearId)->pluck('code')->all();

        $rows = [];

        $source = DB::table('assessment_periods')
            ->where('academic_year_id', $fromYearId)
            ->orderBy('id')
            ->get();

        foreach ($source as $row) {
            if (! in_array((string) $row->code, $existingCodes, true)) {
                $rows[] = [
                    'code' => (string) $row->code,
                    'type' => (string) $row->type,
                    'weight' => (string) $row->weight,
                ];
            }
        }

        return $this->shape(RolloverStep::CopyAssessmentPeriods, ['assessment_periods' => count($rows)], $rows);
    }

    /**
     * @return Preview
     */
    private function feeStructures(int $fromYearId, ?int $toYearId): array
    {
        $structures = 0;
        $rows = [];

        $source = DB::table('fee_structures')
            ->where('academic_year_id', $fromYearId)
            ->where('status', '!=', 'archived')
            ->orderBy('id')
            ->get();

        foreach ($source as $row) {
            $exists = $toYearId !== null && DB::table('fee_structures')
                ->where('academic_year_id', $toYearId)
                ->where('school_section_id', (int) $row->school_section_id)
                ->where('class_level_id', (int) $row->class_level_id)
                ->where('stream_id', (int) $row->stream_id)
                ->where('enrollment_status_scope', (string) $row->enrollment_status_scope)
                ->where('boarding_scope', (string) $row->boarding_scope)
                ->exists();

            if (! $exists) {
                $structures++;
                $rows[] = ['name' => (string) $row->name, 'status' => (string) $row->status];
            }
        }

        $globalPlans = (int) DB::table('installment_plans')
            ->where('academic_year_id', $fromYearId)
            ->where('fee_structure_id', 0)
            ->count();

        return $this->shape(RolloverStep::CopyFeeStructures, [
            'fee_structures' => $structures,
            'global_installment_plans' => $globalPlans,
        ], $rows);
    }

    /**
     * Steps 6-9 belong to the people-and-money engine; their previews here
     * are the headline counts the wizard needs before Apply.
     *
     * @return Preview
     */
    private function promotions(int $fromYearId): array
    {
        $active = (int) DB::table('enrollments')
            ->where('academic_year_id', $fromYearId)
            ->where('status', 'active')
            ->count();

        $decided = (int) DB::table('promotion_decisions')
            ->join('enrollments', 'enrollments.id', '=', 'promotion_decisions.enrollment_id')
            ->where('enrollments.academic_year_id', $fromYearId)
            ->count();

        return $this->shape(RolloverStep::PromoteStudents, [
            'active_enrollments' => $active,
            'decided' => $decided,
            'undecided' => max(0, $active - $decided),
        ], []);
    }

    /**
     * @return Preview
     */
    private function balances(int $fromYearId): array
    {
        $credit = (int) DB::table('payments')
            ->where('academic_year_id', $fromYearId)
            ->where('unallocated_amount', '>', 0)
            ->distinct()
            ->count('student_id');

        return $this->shape(RolloverStep::CarryBalances, [
            'students_with_credit' => $credit,
        ], []);
    }

    /**
     * @return Preview
     */
    private function leavers(int $fromYearId): array
    {
        $byDecision = DB::table('promotion_decisions')
            ->join('enrollments', 'enrollments.id', '=', 'promotion_decisions.enrollment_id')
            ->where('enrollments.academic_year_id', $fromYearId)
            ->whereIn('promotion_decisions.decision', ['graduated', 'withdrawn'])
            ->groupBy('promotion_decisions.decision')
            ->selectRaw('promotion_decisions.decision AS decision, COUNT(*) AS n')
            ->pluck('n', 'decision');

        return $this->shape(RolloverStep::ArchiveLeavers, [
            'graduated' => (int) ($byDecision['graduated'] ?? 0),
            'withdrawn' => (int) ($byDecision['withdrawn'] ?? 0),
        ], []);
    }

    /**
     * @return Preview
     */
    private function teachers(?int $toYearId): array
    {
        $allocations = $toYearId === null ? 0 : (int) DB::table('subject_allocations')
            ->where('academic_year_id', $toYearId)
            ->where('is_active', true)
            ->count();

        return $this->shape(RolloverStep::ReassignTeachers, [
            'allocations_to_review' => $allocations,
        ], []);
    }

    /**
     * @return Preview
     */
    private function flip(RolloverRun $run): array
    {
        $from = RolloverStepMechanics::yearRow($run->academic_year_from_id);
        $to = $run->academic_year_to_id === null
            ? null
            : RolloverStepMechanics::yearRow($run->academic_year_to_id);

        return $this->shape(RolloverStep::FlipActiveYear, [
            'academic_years' => $to === null ? 0 : 1,
        ], [[
            'from' => (string) $from->code,
            'from_status' => (string) $from->status,
            'to' => $to === null ? null : (string) $to->code,
        ]]);
    }
}
