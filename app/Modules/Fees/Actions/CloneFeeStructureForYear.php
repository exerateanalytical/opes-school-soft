<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Models\FeeStructure;
use App\Modules\Fees\Models\InstallmentPlan;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Identity\Domain\Permission;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Clones one fee structure - lines and attached instalment plans - onto a new
 * academic year, with an optional flat uplift (docs/specs/08-operations.md
 * §6.2 step 5: "Copy with an uplift option, instalment due dates shifted, sum
 * constraint re-validated"). This is the Fees-owned door the rollover
 * wizard's step 5 walks through (phase-07 plan §5: the copy must live in Fees
 * because only Fees may touch its own models).
 *
 * Money discipline (00-core §7):
 *  - whole-franc integer arithmetic only; the uplift is basis points
 *    (500 bp = 5%), applied as intdiv(amount * (10000 + bp), 10000);
 *  - for FIXED instalment plans the rounding residual is absorbed by the
 *    LAST tranche (00-core §7.3), so Σ(new tranches) equals the uplifted
 *    old total exactly;
 *  - PERCENTAGE plans copy verbatim - their Σ percentage_bp = 1 000 000
 *    invariant is untouched by an uplift and is re-asserted here.
 *
 * `term_id` on structure lines references the OUTGOING year's assessment
 * periods; each is re-mapped onto the new year's period carrying the same
 * code (the rollover copies periods at step 4, before this runs).
 *
 * Idempotent by the structures' scope unique key: if the target year already
 * carries a structure with the same scope and shifted effective_from, the
 * clone is skipped and nothing is returned for it - only rows this call
 * CREATES appear in the result, which is exactly what the undo ledger needs.
 *
 * @phpstan-type CloneResult array{
 *     fee_structures: list<int>,
 *     fee_structure_lines: list<int>,
 *     installment_plans: list<int>,
 *     installment_plan_lines: list<int>,
 * }
 */
final class CloneFeeStructureForYear
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @return CloneResult
     */
    public function handle(
        int $feeStructureId,
        int $toAcademicYearId,
        int $dayOffset,
        int $upliftBp = 0,
        ?Actor $actor = null,
    ): array {
        Gate::authorize(Permission::FeeConfigure->value);

        if ($upliftBp <= -10_000) {
            throw new DomainException('An uplift below -100% would produce negative fees.');
        }

        /** @var FeeStructure $source */
        $source = FeeStructure::query()->with('lines')->findOrFail($feeStructureId);

        if ($source->academic_year_id === $toAcademicYearId) {
            throw new DomainException('Cannot clone a fee structure onto its own academic year.');
        }

        $result = [
            'fee_structures' => [],
            'fee_structure_lines' => [],
            'installment_plans' => [],
            'installment_plan_lines' => [],
        ];

        $newEffectiveFrom = $source->effective_from->copy()->addDays($dayOffset)->toDateString();

        $alreadyThere = FeeStructure::query()
            ->where('academic_year_id', $toAcademicYearId)
            ->where('school_section_id', $source->school_section_id)
            ->where('class_level_id', $source->class_level_id)
            ->where('stream_id', $source->stream_id)
            ->where('enrollment_status_scope', $source->enrollment_status_scope)
            ->where('boarding_scope', $source->boarding_scope)
            ->whereDate('effective_from', $newEffectiveFrom)
            ->exists();

        if ($alreadyThere) {
            return $result;
        }

        return DB::transaction(function () use ($source, $toAcademicYearId, $dayOffset, $upliftBp, $newEffectiveFrom, $actor, $result): array {
            $clone = FeeStructure::query()->create([
                'academic_year_id' => $toAcademicYearId,
                'school_section_id' => $source->school_section_id,
                'class_level_id' => $source->class_level_id,
                'stream_id' => $source->stream_id,
                'enrollment_status_scope' => $source->enrollment_status_scope,
                'boarding_scope' => $source->boarding_scope,
                'name' => $source->name,
                'status' => $source->status,
                'version' => 1,
                'effective_from' => $newEffectiveFrom,
                'effective_to' => $source->effective_to?->copy()->addDays($dayOffset)->toDateString(),
            ]);

            $cloneId = (int) $clone->getKey();
            $result['fee_structures'][] = $cloneId;

            foreach ($source->lines as $line) {
                $newLine = $clone->lines()->create([
                    'fee_item_id' => $line->fee_item_id,
                    'amount' => self::uplift($line->amount, $upliftBp),
                    'term_id' => $this->mapTermId($line->term_id, $toAcademicYearId),
                    'service_period_start' => $line->service_period_start?->copy()->addDays($dayOffset)->toDateString(),
                    'service_period_end' => $line->service_period_end?->copy()->addDays($dayOffset)->toDateString(),
                    'is_optional' => $line->is_optional,
                    'display_order' => $line->display_order,
                ]);

                $result['fee_structure_lines'][] = (int) $newLine->getKey();
            }

            $plans = InstallmentPlan::query()
                ->with('lines')
                ->where('academic_year_id', $source->academic_year_id)
                ->where('fee_structure_id', (int) $source->getKey())
                ->orderBy('id')
                ->get();

            foreach ($plans as $plan) {
                [$planId, $planLineIds] = $this->clonePlan($plan, $toAcademicYearId, $cloneId, $dayOffset, $upliftBp);
                $result['installment_plans'][] = $planId;
                $result['installment_plan_lines'] = array_merge($result['installment_plan_lines'], $planLineIds);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: FeeStructure::class,
                auditableId: $cloneId,
                after: [
                    'cloned_from' => (int) $source->getKey(),
                    'academic_year_id' => $toAcademicYearId,
                    'uplift_bp' => $upliftBp,
                    'day_offset' => $dayOffset,
                    'lines' => count($result['fee_structure_lines']),
                    'plans' => count($result['installment_plans']),
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $result;
        });
    }

    /**
     * Whole-franc uplift: floor of amount x (1 + bp/10000). Public so the
     * rollover step can apply the identical arithmetic to year-global
     * instalment plans without duplicating the rounding rule.
     */
    public static function uplift(int $amount, int $upliftBp): int
    {
        if ($amount < 0) {
            throw new DomainException('A fee amount cannot be negative.');
        }

        return intdiv($amount * (10_000 + $upliftBp), 10_000);
    }

    /**
     * @param  list<array{sequence_no: int, fixed_amount: int}>  $tranches ordered by sequence_no
     * @return array<int, int> sequence_no => uplifted amount, residual on the last tranche (00-core §7.3)
     */
    public static function upliftFixedTranches(array $tranches, int $upliftBp): array
    {
        $total = 0;

        foreach ($tranches as $tranche) {
            $total += $tranche['fixed_amount'];
        }

        $target = self::uplift($total, $upliftBp);
        $out = [];
        $running = 0;
        $last = array_key_last($tranches);

        foreach ($tranches as $index => $tranche) {
            if ($index === $last) {
                $out[$tranche['sequence_no']] = $target - $running;
            } else {
                $uplifted = self::uplift($tranche['fixed_amount'], $upliftBp);
                $out[$tranche['sequence_no']] = $uplifted;
                $running += $uplifted;
            }
        }

        return $out;
    }

    /**
     * @return array{0: int, 1: list<int>} the new plan id and its line ids
     */
    private function clonePlan(
        InstallmentPlan $plan,
        int $toAcademicYearId,
        int $cloneStructureId,
        int $dayOffset,
        int $upliftBp,
    ): array {
        $lines = $plan->lines->values();

        // Re-assert the §2.6 sum constraint on percentage plans before
        // copying - a corrupt source must refuse, not propagate.
        if ($plan->basis->value === 'percentage') {
            $totalBp = 0;

            foreach ($lines as $line) {
                $totalBp += (int) $line->percentage_bp;
            }

            if ($totalBp !== 1_000_000) {
                throw new DomainException(sprintf(
                    'Instalment plan "%s" sums to %d bp, not 1 000 000; fix it before rolling over.',
                    $plan->name,
                    $totalBp,
                ));
            }
        }

        $fixedBySequence = [];

        if ($plan->basis->value === 'fixed') {
            $tranches = [];

            foreach ($lines as $line) {
                $tranches[] = [
                    'sequence_no' => $line->sequence_no,
                    'fixed_amount' => (int) $line->fixed_amount,
                ];
            }

            $fixedBySequence = self::upliftFixedTranches($tranches, $upliftBp);
        }

        $newPlan = InstallmentPlan::query()->create([
            'academic_year_id' => $toAcademicYearId,
            'name' => $plan->name,
            'fee_structure_id' => $cloneStructureId,
            'basis' => $plan->basis,
            'is_default' => $plan->is_default,
        ]);

        $lineIds = [];

        foreach ($lines as $line) {
            $newLine = $newPlan->lines()->create([
                'sequence_no' => $line->sequence_no,
                'label' => $line->label,
                'label_fr' => $line->label_fr,
                'percentage_bp' => $line->percentage_bp,
                'fixed_amount' => $fixedBySequence[$line->sequence_no] ?? $line->fixed_amount,
                'due_date' => $line->due_date?->copy()->addDays($dayOffset)->toDateString(),
                'due_offset_days' => $line->due_offset_days,
            ]);

            $lineIds[] = (int) $newLine->getKey();
        }

        return [(int) $newPlan->getKey(), $lineIds];
    }

    /**
     * Map a term-scoped line onto the new year's period with the same code.
     * Assessment periods are Academics rows - read via DB::table only.
     */
    private function mapTermId(int $termId, int $toAcademicYearId): int
    {
        if ($termId === 0) {
            return 0;
        }

        $code = DB::table('assessment_periods')->where('id', $termId)->value('code');

        if ($code === null) {
            throw new DomainException(sprintf('A fee structure line references term %d which does not exist.', $termId));
        }

        $mapped = DB::table('assessment_periods')
            ->where('academic_year_id', $toAcademicYearId)
            ->where('code', (string) $code)
            ->value('id');

        if ($mapped === null) {
            throw new DomainException(sprintf(
                'The new year has no assessment period with code %s - copy the periods (step 4) before the fee structures.',
                (string) $code,
            ));
        }

        return (int) $mapped;
    }
}
