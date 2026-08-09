<?php

declare(strict_types=1);

namespace App\Modules\Fees\Actions;

use App\Modules\Fees\Domain\InstallmentBasis;
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
 * 04-fees.md §2.6, including the sum constraint (H):
 *
 *  - percentage basis: Σ percentage_bp = 1 000 000 EXACTLY. The residual
 *    franc problem is not solved here - amounts are produced at application
 *    time by Money::allocate (largest remainder; last tranche absorbs).
 *  - fixed basis: validated against the invoice total at application time,
 *    not at save.
 *
 * Each line carries exactly one of due_date / due_offset_days.
 */
final class SaveInstallmentPlan
{
    public function __construct(private readonly WriteAuditEntry $audit)
    {
    }

    /**
     * @param  list<array{sequence_no: int, label: string, label_fr: string, percentage_bp?: int|null, fixed_amount?: int|null, due_date?: string|null, due_offset_days?: int|null}>  $lines
     */
    public function handle(
        int $academicYearId,
        string $name,
        InstallmentBasis $basis,
        array $lines,
        int $feeStructureId = InstallmentPlan::GLOBAL,
        bool $isDefault = false,
        ?Actor $actor = null,
    ): InstallmentPlan {
        Gate::authorize(Permission::FeeConfigure->value);

        if ($lines === []) {
            throw new DomainException('An instalment plan needs at least one tranche.');
        }

        if ($feeStructureId !== InstallmentPlan::GLOBAL
            && ! FeeStructure::query()->whereKey($feeStructureId)->exists()) {
            throw new DomainException('The fee structure does not exist.');
        }

        $this->assertLines($basis, $lines);

        return DB::transaction(function () use ($academicYearId, $name, $basis, $lines, $feeStructureId, $isDefault, $actor): InstallmentPlan {
            $plan = InstallmentPlan::query()->create([
                'academic_year_id' => $academicYearId,
                'name' => $name,
                'fee_structure_id' => $feeStructureId,
                'basis' => $basis,
                'is_default' => $isDefault,
            ]);

            foreach ($lines as $line) {
                $plan->lines()->create([
                    'sequence_no' => $line['sequence_no'],
                    'label' => $line['label'],
                    'label_fr' => $line['label_fr'],
                    'percentage_bp' => $line['percentage_bp'] ?? null,
                    'fixed_amount' => $line['fixed_amount'] ?? null,
                    'due_date' => $line['due_date'] ?? null,
                    'due_offset_days' => $line['due_offset_days'] ?? null,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Fees',
                auditableType: InstallmentPlan::class,
                auditableId: (int) $plan->getKey(),
                after: [
                    'name' => $name,
                    'basis' => $basis->value,
                    'fee_structure_id' => $feeStructureId,
                    'is_default' => $isDefault,
                    'line_count' => count($lines),
                ],
                actor: $actor ?? auth()->user()?->toAuditActor() ?? Actor::system(),
            );

            return $plan;
        });
    }

    /**
     * @param  list<array{sequence_no: int, label: string, label_fr: string, percentage_bp?: int|null, fixed_amount?: int|null, due_date?: string|null, due_offset_days?: int|null}>  $lines
     */
    private function assertLines(InstallmentBasis $basis, array $lines): void
    {
        $expected = 1;
        $totalBp = 0;

        foreach ($lines as $line) {
            if ($line['sequence_no'] !== $expected) {
                throw new DomainException('Instalment tranches must be numbered 1..n without gaps.');
            }

            $expected++;

            $hasDate = ($line['due_date'] ?? null) !== null;
            $hasOffset = ($line['due_offset_days'] ?? null) !== null;

            if ($hasDate === $hasOffset) {
                throw new DomainException('Each tranche carries exactly one of due_date or due_offset_days.');
            }

            $bp = $line['percentage_bp'] ?? null;
            $fixed = $line['fixed_amount'] ?? null;

            if ($basis === InstallmentBasis::Percentage) {
                if ($bp === null || $fixed !== null) {
                    throw new DomainException('A percentage plan\'s tranches carry percentage_bp, not fixed_amount.');
                }

                if ($bp <= 0) {
                    throw new DomainException('A tranche percentage must be positive.');
                }

                $totalBp += $bp;
            } else {
                if ($fixed === null || $bp !== null) {
                    throw new DomainException('A fixed plan\'s tranches carry fixed_amount, not percentage_bp.');
                }

                if ($fixed < 0) {
                    throw new DomainException('A tranche amount cannot be negative.');
                }
            }
        }

        // §2.6: 1 000 000 bp = 100%, exactly - no "close enough".
        if ($basis === InstallmentBasis::Percentage && $totalBp !== 1_000_000) {
            throw new DomainException(sprintf(
                'A percentage plan must sum to exactly 1 000 000 basis points (100%%); got %d.',
                $totalBp,
            ));
        }
    }
}
