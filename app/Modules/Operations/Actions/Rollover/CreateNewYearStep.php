<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Academics\Actions\CreateAcademicYear;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 1 (docs/specs/08-operations.md §6.2): create the new academic
 * year, starting exactly the day after the outgoing one ends. Creation is
 * DELEGATED to Academics\Actions\CreateAcademicYear, where the
 * contiguous-gapless invariant (00-core §8) is enforced under lock - this
 * step adds nothing to that rule, it only supplies the dates.
 *
 * Idempotent: if a year already starts on the expected day (a crashed
 * attempt's committed create, or a year the school made by hand), it is
 * ADOPTED instead of re-created. An adopted year is recorded in the undo
 * ledger only when its code matches this run's requested code - undoing a
 * rollover must never delete a year the school created independently.
 */
final class CreateNewYearStep
{
    public function __construct(private readonly CreateAcademicYear $createYear)
    {
    }

    public function handle(
        RolloverRun $run,
        string $code,
        string $name,
        Actor $actor,
        ?string $endsOn = null,
    ): RolloverRun {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::CreateNewYear);

        $from = RolloverStepMechanics::yearRow($run->academic_year_from_id);

        $starts = Carbon::parse((string) $from->ends_on)->addDay()->startOfDay();
        $lengthDays = (int) Carbon::parse((string) $from->starts_on)
            ->diffInDays(Carbon::parse((string) $from->ends_on));
        $ends = $endsOn === null
            ? $starts->copy()->addDays($lengthDays)
            : Carbon::parse($endsOn)->startOfDay();

        $existing = DB::table('academic_years')
            ->whereDate('starts_on', $starts->toDateString())
            ->first();

        $created = false;

        if ($existing !== null) {
            $toYearId = (int) $existing->id;
            $ownRow = (string) $existing->code === $code;
        } else {
            $year = $this->createYear->handle(
                code: $code,
                name: $name,
                startsOn: $starts->toDateString(),
                endsOn: $ends->toDateString(),
                actor: $actor,
            );

            $toYearId = (int) $year->getKey();
            $created = true;
            $ownRow = true;
        }

        DB::transaction(function () use ($run, $toYearId, $created, $ownRow): void {
            $run->forceFill(['academic_year_to_id' => $toYearId])->save();

            if ($ownRow) {
                RolloverStepMechanics::recordArtifact($run, RolloverStep::CreateNewYear, 'academic_years', $toYearId);
            }

            RolloverStepMechanics::completeStep($run, RolloverStep::CreateNewYear, [
                'academic_year_to_id' => $toYearId,
                'created' => $created,
                'adopted_existing' => ! $created,
            ]);
        });

        return $run->refresh();
    }
}
