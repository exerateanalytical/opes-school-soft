<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\CopyAssessmentPeriodTree;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 4 (docs/specs/08-operations.md §6.2): copy the assessment
 * period structure (trimestres/sequences/months) with dates shifted by the
 * year offset. The whole copy - including the Σweights re-validation the
 * step's guard demands - is DELEGATED to
 * Academics\Actions\CopyAssessmentPeriodTree, the owning module's door;
 * this step only records the created ids in the undo ledger and advances
 * the run.
 */
final class CopyAssessmentPeriodsStep
{
    public function __construct(private readonly CopyAssessmentPeriodTree $copyTree)
    {
    }

    public function handle(RolloverRun $run, Actor $actor): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::CopyAssessmentPeriods);

        $toYearId = RolloverStepMechanics::targetYearId($run);

        $createdIds = $this->copyTree->handle(
            fromAcademicYearId: $run->academic_year_from_id,
            toAcademicYearId: $toYearId,
            actor: $actor,
        );

        foreach ($createdIds as $periodId) {
            RolloverStepMechanics::recordArtifact(
                $run,
                RolloverStep::CopyAssessmentPeriods,
                'assessment_periods',
                $periodId,
            );
        }

        RolloverStepMechanics::completeStep($run, RolloverStep::CopyAssessmentPeriods, [
            'created' => count($createdIds),
        ]);

        return $run->refresh();
    }
}
