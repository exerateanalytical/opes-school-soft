<?php

declare(strict_types=1);

namespace App\Modules\Operations\Actions\Rollover;

use App\Modules\Fees\Actions\CloneFeeStructureForYear;
use App\Modules\Fees\Actions\SaveInstallmentPlan;
use App\Modules\Fees\Domain\InstallmentBasis;
use App\Modules\Operations\Actions\Rollover\Support\RolloverStepMechanics;
use App\Modules\Operations\Domain\RolloverStep;
use App\Modules\Operations\Models\RolloverRun;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Wizard step 5 (docs/specs/08-operations.md §6.2): copy the outgoing year's
 * fee structures and instalment plans with an optional flat uplift (basis
 * points; 500 = +5%), due dates shifted by the year offset, sum constraints
 * re-validated, rounding residual absorbed by the last tranche (00-core
 * §7.3).
 *
 * All of the money logic is DELEGATED to Fees:
 *  - per-structure clones (lines + structure-scoped plans) via
 *    Fees\Actions\CloneFeeStructureForYear;
 *  - year-GLOBAL instalment plans (fee_structure_id = 0) via
 *    Fees\Actions\SaveInstallmentPlan, with the identical uplift arithmetic
 *    borrowed from the clone door.
 *
 * This step reads via DB::table, records every created row in the undo
 * ledger, and advances the run. Idempotent: the clone door skips structures
 * that already exist on the target scope, and global plans are skipped by
 * (year, name).
 */
final class CopyFeeStructuresStep
{
    public function __construct(
        private readonly CloneFeeStructureForYear $cloneStructure,
        private readonly SaveInstallmentPlan $savePlan,
    ) {
    }

    public function handle(RolloverRun $run, Actor $actor, int $upliftBp = 0): RolloverRun
    {
        Gate::authorize(StartRolloverRun::PERMISSION);
        RolloverStepMechanics::assertRunnable($run, RolloverStep::CopyFeeStructures);

        $toYearId = RolloverStepMechanics::targetYearId($run);
        $from = RolloverStepMechanics::yearRow($run->academic_year_from_id);
        $to = RolloverStepMechanics::yearRow($toYearId);
        $dayOffset = RolloverStepMechanics::dayOffset($from, $to);

        $structures = DB::table('fee_structures')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('status', '!=', 'archived')
            ->orderBy('id')
            ->pluck('id');

        $structuresCreated = 0;
        $structuresSkipped = 0;
        $plansCreated = 0;

        foreach ($structures as $structureId) {
            $created = $this->cloneStructure->handle(
                feeStructureId: (int) $structureId,
                toAcademicYearId: $toYearId,
                dayOffset: $dayOffset,
                upliftBp: $upliftBp,
                actor: $actor,
            );

            if ($created['fee_structures'] === []) {
                $structuresSkipped++;

                continue;
            }

            $structuresCreated++;
            $plansCreated += count($created['installment_plans']);

            foreach ($created as $table => $ids) {
                foreach ($ids as $id) {
                    RolloverStepMechanics::recordArtifact($run, RolloverStep::CopyFeeStructures, $table, $id);
                }
            }
        }

        $plansCreated += $this->copyGlobalPlans($run, $toYearId, $dayOffset, $upliftBp, $actor);

        RolloverStepMechanics::completeStep($run, RolloverStep::CopyFeeStructures, [
            'structures_created' => $structuresCreated,
            'structures_skipped' => $structuresSkipped,
            'plans_created' => $plansCreated,
            'uplift_bp' => $upliftBp,
            'day_offset' => $dayOffset,
        ]);

        return $run->refresh();
    }

    /**
     * Year-global instalment plans (fee_structure_id = 0) are not attached to
     * any structure, so the clone door never sees them - they are re-created
     * through Fees\Actions\SaveInstallmentPlan, which re-asserts the §2.6 sum
     * constraint on the copied tranches.
     */
    private function copyGlobalPlans(RolloverRun $run, int $toYearId, int $dayOffset, int $upliftBp, Actor $actor): int
    {
        $plans = DB::table('installment_plans')
            ->where('academic_year_id', $run->academic_year_from_id)
            ->where('fee_structure_id', 0)
            ->orderBy('id')
            ->get();

        $created = 0;

        foreach ($plans as $plan) {
            $exists = DB::table('installment_plans')
                ->where('academic_year_id', $toYearId)
                ->where('fee_structure_id', 0)
                ->where('name', (string) $plan->name)
                ->exists();

            if ($exists) {
                continue;
            }

            $sourceLines = DB::table('installment_plan_lines')
                ->where('installment_plan_id', (int) $plan->id)
                ->orderBy('sequence_no')
                ->get();

            $isFixed = (string) $plan->basis === InstallmentBasis::Fixed->value;
            $fixedBySequence = [];

            if ($isFixed) {
                $tranches = [];

                foreach ($sourceLines as $line) {
                    $tranches[] = [
                        'sequence_no' => (int) $line->sequence_no,
                        'fixed_amount' => (int) $line->fixed_amount,
                    ];
                }

                $fixedBySequence = CloneFeeStructureForYear::upliftFixedTranches($tranches, $upliftBp);
            }

            $lines = [];

            foreach ($sourceLines as $line) {
                $lines[] = [
                    'sequence_no' => (int) $line->sequence_no,
                    'label' => (string) $line->label,
                    'label_fr' => (string) $line->label_fr,
                    'percentage_bp' => $line->percentage_bp === null ? null : (int) $line->percentage_bp,
                    'fixed_amount' => $isFixed ? $fixedBySequence[(int) $line->sequence_no] : null,
                    'due_date' => $line->due_date === null
                        ? null
                        : Carbon::parse((string) $line->due_date)->addDays($dayOffset)->toDateString(),
                    'due_offset_days' => $line->due_offset_days === null ? null : (int) $line->due_offset_days,
                ];
            }

            $newPlan = $this->savePlan->handle(
                academicYearId: $toYearId,
                name: (string) $plan->name,
                basis: InstallmentBasis::from((string) $plan->basis),
                lines: $lines,
                feeStructureId: 0,
                isDefault: (bool) $plan->is_default,
                actor: $actor,
            );

            $newPlanId = (int) $newPlan->getKey();
            RolloverStepMechanics::recordArtifact($run, RolloverStep::CopyFeeStructures, 'installment_plans', $newPlanId);

            $lineIds = DB::table('installment_plan_lines')
                ->where('installment_plan_id', $newPlanId)
                ->pluck('id');

            foreach ($lineIds as $lineId) {
                RolloverStepMechanics::recordArtifact($run, RolloverStep::CopyFeeStructures, 'installment_plan_lines', (int) $lineId);
            }

            $created++;
        }

        return $created;
    }
}
