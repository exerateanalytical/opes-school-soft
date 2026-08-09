<?php

declare(strict_types=1);

namespace App\Modules\Welfare\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportAllocation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/plans/phase-10.md §4 (W1). Ends an active allocation - the student
 * stops riding (withdrawal, guardian now drives, non-payment handled by
 * the fees side). The row stays as history; nothing is deleted.
 */
final class EndTransportAllocation
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $allocationId, Carbon $endsOn, Actor $actor): TransportAllocation
    {
        Gate::authorize(TransportPermission::MANAGE);

        return DB::transaction(function () use ($allocationId, $endsOn, $actor): TransportAllocation {
            /** @var TransportAllocation $allocation */
            $allocation = TransportAllocation::query()->lockForUpdate()->findOrFail($allocationId);

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
                auditableType: TransportAllocation::class,
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
