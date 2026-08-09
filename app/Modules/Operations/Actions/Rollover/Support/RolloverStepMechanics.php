<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover\Support;

use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverArtifact;
use App\Modules\Operations\Models\RolloverRun;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The mechanics every rollover step Action shares (docs/specs/08-operations.md
 * §6.2–§6.3): the strictly-in-order step guard, the undo ledger write, and the
 * step-completion bookkeeping that makes a killed run resumable.
 *
 * Static and stateless on purpose - each step Action stays a self-contained
 * door, and the shared behaviour cannot accumulate state a crash would lose.
 */
final class RolloverStepMechanics
{
    private function __construct()
    {
    }

    /**
     * A step may execute only while the run is resumable and standing exactly
     * at that step - no skipping, no re-running a completed step (§6.2; a
     * resumed run restarts at the first INCOMPLETE step).
     */
    public static function assertRunnable(RolloverRun $run, RolloverStep $step): void
    {
        if (! $run->status()->isResumable()) {
            throw new DomainException(sprintf(
                'Rollover run %d is %s and cannot execute steps.',
                (int) $run->getKey(),
                $run->status,
            ));
        }

        if ($run->current_step !== $step->value) {
            throw new DomainException(sprintf(
                'Rollover run %d stands at step %d; step %d cannot run now - steps execute strictly in order.',
                (int) $run->getKey(),
                $run->current_step,
                $step->value,
            ));
        }
    }

    /**
     * The new year id, present from step 2 onward.
     */
    public static function targetYearId(RolloverRun $run): int
    {
        if ($run->academic_year_to_id === null) {
            throw new DomainException(
                'The new academic year has not been created yet - run step 1 first.'
            );
        }

        return $run->academic_year_to_id;
    }

    /**
     * One row of the undo ledger (phase-07 plan decision 2). firstOrCreate so
     * a resumed step re-recording a row a crashed attempt already logged is a
     * no-op, not a unique-key explosion.
     */
    public static function recordArtifact(RolloverRun $run, RolloverStep $step, string $table, int $id): void
    {
        RolloverArtifact::query()->firstOrCreate(
            [
                'rollover_run_id' => (int) $run->getKey(),
                'entity_type' => $table,
                'entity_id' => $id,
            ],
            ['step' => $step->value],
        );
    }

    /**
     * Record the step's outcome in `step_states` and advance `current_step`
     * so the next step becomes runnable. The last step does not advance -
     * FlipActiveYearStep marks the run completed instead.
     *
     * @param  array<string, mixed>  $state
     */
    public static function completeStep(RolloverRun $run, RolloverStep $step, array $state): void
    {
        $states = $run->step_states ?? [];
        $states[(string) $step->value] = $state + ['completed_at' => now()->toIso8601String()];

        $next = $step->next();

        $run->forceFill([
            'step_states' => $states,
            'current_step' => $next === null ? $step->value : $next->value,
        ])->save();
    }

    /**
     * Cross-module read of one academic_years row (DB::table, never the
     * Academics model - tests/Architecture/ModuleBoundaryTest.php).
     */
    public static function yearRow(int $academicYearId): stdClass
    {
        $row = DB::table('academic_years')->where('id', $academicYearId)->first();

        if ($row === null) {
            throw new DomainException(sprintf('Academic year %d does not exist.', $academicYearId));
        }

        return $row;
    }

    /**
     * How many days the new year is shifted from the outgoing one - the
     * offset every copied date (periods, due dates, service periods) moves by
     * (§6.2 steps 4-5 "dates shifted by the year offset").
     */
    public static function dayOffset(stdClass $fromYear, stdClass $toYear): int
    {
        return (int) Carbon::parse((string) $fromYear->starts_on)
            ->diffInDays(Carbon::parse((string) $toYear->starts_on));
    }
}
