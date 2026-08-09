<?php

declare(strict_types=1);

namespace App\Modules\Assets\Actions;

use App\Modules\Assets\Domain\AssetPermission;
use App\Modules\Assets\Domain\DepreciationRunStatus;
use App\Modules\Assets\Models\DepreciationRun;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * 06-assets-stores.md §4.1 - calculated → approved, maker/checker: the
 * approver may NOT be the person who ran the calculation (02-accounting
 * segregation). The transition is a conditional UPDATE ... WHERE status =
 * 'calculated' with an affected-rows check, exactly as the spec demands -
 * a concurrent approval loses cleanly.
 */
final class ApproveDepreciationRun
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $runId, Actor $actor): DepreciationRun
    {
        Gate::authorize(AssetPermission::DEPRECIATE);

        return DB::transaction(function () use ($runId, $actor): DepreciationRun {
            /** @var DepreciationRun|null $run */
            $run = DepreciationRun::query()->lockForUpdate()->find($runId);

            if ($run === null) {
                throw new DomainException("Depreciation run {$runId} does not exist.");
            }

            if ($run->status !== DepreciationRunStatus::Calculated) {
                throw new DomainException(
                    "Depreciation run #{$runId} is {$run->status->value}; only a calculated run can be approved."
                );
            }

            if ($run->run_by === $actor->id) {
                throw new DomainException(
                    'A depreciation run cannot be approved by the person who ran it (maker/checker, §4.1).'
                );
            }

            $affected = DepreciationRun::query()
                ->whereKey($runId)
                ->where('status', DepreciationRunStatus::Calculated->value)
                ->update([
                    'status' => DepreciationRunStatus::Approved->value,
                    'approved_by' => $actor->id,
                    'approved_at' => now(),
                ]);

            if ($affected !== 1) {
                throw new DomainException(
                    "Depreciation run #{$runId} changed state concurrently; approval aborted."
                );
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Assets',
                auditableType: DepreciationRun::class,
                auditableId: $runId,
                before: ['status' => DepreciationRunStatus::Calculated->value],
                after: ['status' => DepreciationRunStatus::Approved->value],
                actor: $actor,
            );

            return $run->refresh();
        });
    }
}
