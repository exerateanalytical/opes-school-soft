<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Actions\Conduct;

use App\Modules\Assessment\Models\ConductAssessment;
use App\Modules\Assessment\Models\ConductScale;
use App\Modules\Assessment\Models\ConductScaleLevel;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Records or updates the five-dimension conduct block for one student in one
 * assessment period (01-assessment §12.3).
 *
 * Upsert on `UNIQUE(enrollment_id, assessment_period_id)`: a class master
 * revising a grade before the conseil should not create a second row, and
 * the unique key means the database would refuse anyway.
 *
 * Every level supplied is checked to belong to the scale being used. Without
 * that check a caller could mix a level from the primary A/ECA/NA scale into
 * a secondary TB/B/AB/P/M assessment, and the bulletin would print a grade
 * from a scale nobody chose.
 */
final class RecordConductAssessment
{
    /**
     * @param  array{conduite: int, travail: int, assiduite: int, discipline: int, tenue: int}  $levels
     */
    public function handle(
        int $enrollmentId,
        int $assessmentPeriodId,
        int $conductScaleId,
        array $levels,
        int $assessedByStaffId,
        Actor $actor,
        ?string $notes = null,
    ): ConductAssessment {
        Gate::authorize(Permission::AssessmentConfigure->value);

        /** @var ConductScale $scale */
        $scale = ConductScale::query()->findOrFail($conductScaleId);

        $validLevelIds = ConductScaleLevel::query()
            ->where('conduct_scale_id', $scale->getKey())
            ->pluck('id')
            ->all();

        foreach ($levels as $dimension => $levelId) {
            if (! in_array($levelId, $validLevelIds, true)) {
                throw new DomainException(sprintf(
                    'Level %d is not part of conduct scale %s, so it cannot grade "%s".',
                    $levelId,
                    $scale->code,
                    $dimension,
                ));
            }
        }

        return DB::transaction(function () use (
            $enrollmentId, $assessmentPeriodId, $scale, $levels, $assessedByStaffId, $notes
        ): ConductAssessment {
            /** @var ConductAssessment $assessment */
            $assessment = ConductAssessment::query()->updateOrCreate(
                [
                    'enrollment_id' => $enrollmentId,
                    'assessment_period_id' => $assessmentPeriodId,
                ],
                [
                    'conduct_scale_id' => $scale->getKey(),
                    'conduite_level_id' => $levels['conduite'],
                    'travail_level_id' => $levels['travail'],
                    'assiduite_level_id' => $levels['assiduite'],
                    'discipline_level_id' => $levels['discipline'],
                    'tenue_level_id' => $levels['tenue'],
                    'assessed_by_staff_id' => $assessedByStaffId,
                    'assessed_at' => now(),
                    'notes' => $notes,
                ],
            );

            return $assessment;
        });
    }
}
