<?php

declare(strict_types=1);

namespace App\Modules\HR\Actions;

use App\Modules\HR\Domain\HrPermission;
use App\Modules\HR\Models\StaffContract;
use App\Modules\HR\Models\StaffCostAllocation;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use App\Support\Rate\Rate;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Replaces one contract's analytic cost split from an effective date
 * (docs/specs/05-hr-payroll.md 3.7).
 *
 * Invariant, asserted here and nowhere weaker: the percentages sum to
 * EXACTLY 100% - Rate::SCALE basis points. A teacher working across nursery
 * and primary must be split exactly or the per-section analytic P&L is
 * wrong; 99.99% and 100.01% are both silent lies that compound monthly.
 *
 * The split stores PERCENTAGES, not amounts: statutory computation is
 * employer-wide first (H3), and the approve Action later allocates each
 * computed cost with Money's largest-remainder Allocator so the allocated
 * parts sum exactly to the whole.
 *
 * Rows are never edited in place: the set in force is CLOSED (effective_to
 * set, exclusive) and a new set inserted - the same append-only discipline
 * as every effective-dated table here, because a January payslip's analytic
 * split must still be explainable in March.
 */
final class SaveCostAllocation
{
    /**
     * @param  array<int, array{analytic_value_id: int, percentage_bp: int}>  $allocations
     * @return list<StaffCostAllocation>
     */
    public function handle(int $staffContractId, string $effectiveFrom, array $allocations): array
    {
        Gate::authorize(HrPermission::MANAGE);

        $actor = $this->currentActor();

        if ($allocations === []) {
            throw ValidationException::withMessages([
                'allocations' => 'At least one allocation line is required.',
            ]);
        }

        return DB::transaction(function () use ($staffContractId, $effectiveFrom, $allocations, $actor): array {
            /** @var StaffContract $contract */
            $contract = StaffContract::query()->whereKey($staffContractId)->lockForUpdate()->firstOrFail();

            $seen = [];
            $sum = 0;

            foreach ($allocations as $line) {
                if ($line['percentage_bp'] <= 0) {
                    throw ValidationException::withMessages([
                        'allocations' => 'Every allocation line must carry a positive percentage.',
                    ]);
                }

                if (isset($seen[$line['analytic_value_id']])) {
                    throw ValidationException::withMessages([
                        'allocations' => 'The same analytic value appears twice; merge the lines.',
                    ]);
                }

                $seen[$line['analytic_value_id']] = true;
                $sum += $line['percentage_bp'];
            }

            if ($sum !== Rate::SCALE) {
                throw ValidationException::withMessages([
                    'allocations' => sprintf(
                        'Cost allocations must sum to exactly 100%% (%d basis points); these sum to %d.',
                        Rate::SCALE,
                        $sum,
                    ),
                ]);
            }

            // Cross-module read by table, not model (00-core 6.2): the
            // analytic axis belongs to Accounting.
            $validIds = DB::table('analytic_values')
                ->whereIn('id', array_keys($seen))
                ->pluck('id')
                ->all();

            $missing = array_diff(array_keys($seen), $validIds);

            if ($missing !== []) {
                throw ValidationException::withMessages([
                    'allocations' => 'Unknown analytic value(s): '.implode(', ', $missing).'.',
                ]);
            }

            // Close the set currently in force - append-only, never edited.
            StaffCostAllocation::query()
                ->where('staff_contract_id', $contract->id)
                ->whereNull('effective_to')
                ->where('effective_from', '<', $effectiveFrom)
                ->update(['effective_to' => $effectiveFrom]);

            // A same-day re-save replaces the same-day set outright: two
            // sets from one effective date cannot both claim 100%.
            StaffCostAllocation::query()
                ->where('staff_contract_id', $contract->id)
                ->where('effective_from', $effectiveFrom)
                ->delete();

            $rows = [];

            foreach ($allocations as $line) {
                $rows[] = StaffCostAllocation::query()->create([
                    'staff_contract_id' => $contract->id,
                    'analytic_value_id' => $line['analytic_value_id'],
                    'percentage_bp' => $line['percentage_bp'],
                    'effective_from' => $effectiveFrom,
                    'effective_to' => null,
                    'created_by' => $actor->id,
                ]);
            }

            app(WriteAuditEntry::class)->handle(
                action: AuditAction::Updated,
                module: 'HR',
                auditableType: StaffContract::class,
                auditableId: (int) $contract->getKey(),
                after: [
                    'effective_from' => $effectiveFrom,
                    'allocations' => array_map(
                        static fn (array $line): string => $line['analytic_value_id'].':'.$line['percentage_bp'].'bp',
                        $allocations,
                    ),
                ],
                actor: $actor,
            );

            return $rows;
        });
    }

    private function currentActor(): Actor
    {
        // No textual reference to the Identity User model crosses this module
        // boundary; larastan resolves auth()->user() to it on its own.
        return auth()->user()?->toAuditActor() ?? Actor::system();
    }
}
