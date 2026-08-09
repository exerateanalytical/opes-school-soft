<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\HostelPermission;
use App\Modules\Welfare\Models\HostelAllocation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W2). Ends an active bed allocation - the
 * student checks out (withdrawal, became a day student, end of year). The
 * row stays as history; nothing is deleted, and the bed frees itself
 * because the generated active_bed_key goes NULL with the status flip.
 */
final class EndHostelAllocation
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $allocationId, Carbon $endsOn, Actor $actor): HostelAllocation
    {
        Gate::authorize(HostelPermission::MANAGE);

        return DB::transaction(function () use ($allocationId, $endsOn, $actor): HostelAllocation {
            /** @var HostelAllocation $allocation */
            $allocation = HostelAllocation::query()->lockForUpdate()->findOrFail($allocationId);

            if ($allocation->status !== AllocationStatus::Active) {
                throw new DomainException('Only an ACTIVE allocation can be ended.');
            }

            if ($endsOn->lessThan($allocation->starts_on)) {
                throw new DomainException(
                    'An allocation cannot end before it starts ('
                    .$allocation->starts_on->toDateString().').'
                );
            }

            $allocation->fill([
                'status' => AllocationStatus::Ended,
                'ends_on' => $endsOn,
            ])->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Welfare',
                auditableType: HostelAllocation::class,
                auditableId: (int) $allocation->getKey(),
                after: [
                    'status' => AllocationStatus::Ended->value,
                    'ends_on' => $endsOn->toDateString(),
                ],
                actor: $actor,
            );

            return $allocation->refresh();
        });
    }
}
