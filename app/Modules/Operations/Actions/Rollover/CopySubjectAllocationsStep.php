<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\AllocateSubject;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 3 (docs/specs/08-operations.md §6.2): copy the outgoing year's
 * ACTIVE SubjectAllocations into the new academic_year_id, coefficients and
 * grading configuration preserved (01-assessment requires year scoping;
 * coefficients stay editable in the new year only). Source rows are read via
 * DB::table and never touched - RESTRICT protection stays intact.
 *
 * `effective_from_period_id` / `effective_to_period_id` reference the
 * OUTGOING year's periods, which do not exist in the new year yet (periods
 * are copied at step 4) - they are nulled on the copy and the affected
 * subject ids recorded in `step_states` for review in the new year.
 *
 * Writes via Academics\Actions\AllocateSubject (the owning module's door).
 * Idempotent by the (year, level, stream, subject) natural key.
 */
final class CopySubjectAllocationsStep
{
    /** Mirrors SubjectAllocation::STREAM_NONE without importing the model. */
    private const STREAM_NONE = 0;

    public function __construct(private readonly AllocateSubject $allocateSubject)
    {
    }

    public function handle(RolloverRun $run, Actor $actor): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::CopySubjectAllocations);

        $toYearId = RolloverStepMechanics::targetYearId($run);

        $source = DB::table('subject_allocations')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        $created = 0;
        $skipped = 0;
        $effectiveWindowCleared = [];

        foreach ($source as $row) {
            $exists = DB::table('subject_allocations')
                ->where('academic_year_id', $toYearId)
                ->where('class_level_id', (int) $row->class_level_id)
                ->where('stream_id', (int) $row->stream_id)
                ->where('subject_id', (int) $row->subject_id)
                ->exists();

            if ($exists) {
                $skipped++;

                continue;
            }

            /** @var list<int> $requiredComponents */
            $requiredComponents = $row->required_components === null
                ? []
                : (array) json_decode((string) $row->required_components, true, 512, JSON_THROW_ON_ERROR);

            $allocation = $this->allocateSubject->handle(
                academicYearId: $toYearId,
                classLevelId: (int) $row->class_level_id,
                streamId: (int) $row->stream_id === self::STREAM_NONE ? null : (int) $row->stream_id,
                subjectId: (int) $row->subject_id,
                coefficient: (string) $row->coefficient,
                requiredComponents: $requiredComponents,
                subjectGroupId: $row->subject_group_id === null ? null : (int) $row->subject_group_id,
                maxScoreOverride: $row->max_score_override === null ? null : (string) $row->max_score_override,
                isOptional: (bool) $row->is_optional,
                countsTowardAverage: (bool) $row->counts_toward_average,
                effectiveFromPeriodId: null,
                effectiveToPeriodId: null,
            );

            RolloverStepMechanics::recordArtifact(
                $run,
                RolloverStep::CopySubjectAllocations,
                'subject_allocations',
                (int) $allocation->getKey(),
            );
            $created++;

            if ($row->effective_from_period_id !== null || $row->effective_to_period_id !== null) {
                $effectiveWindowCleared[] = (int) $row->subject_id;
            }
        }

        RolloverStepMechanics::completeStep($run, RolloverStep::CopySubjectAllocations, [
            'created' => $created,
            'skipped_existing' => $skipped,
            'effective_window_cleared_subject_ids' => $effectiveWindowCleared,
        ]);

        return $run->refresh();
    }
}
