<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §9 - the exit for a requisition that has
 * left draft: cancelled, never deleted. Blocked once ordering has started -
 * the open POs must be cancelled first, or the audit trail would show
 * orders hanging from a requisition that "never happened".
 */
final class CancelRequisition
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $requisitionId, Actor $actor): PurchaseRequisition
    {
        Gate::authorize(ProcurementPermission::VIEW);

        return DB::transaction(function () use ($requisitionId, $actor): PurchaseRequisition {
            /** @var PurchaseRequisition $requisition */
            $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->lockForUpdate()->firstOrFail();

            $cancellable = in_array($requisition->status, [
                RequisitionStatus::Draft,
                RequisitionStatus::Submitted,
                RequisitionStatus::Approved,
            ], true);

            if (! $cancellable) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Requisition %s is %s and can no longer be cancelled.',
                        $requisition->requisition_no,
                        $requisition->status->value,
                    ),
                ]);
            }

            $requisition->status = RequisitionStatus::Cancelled;
            $requisition->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseRequisition::class,
                auditableId: (int) $requisition->getKey(),
                after: ['status' => RequisitionStatus::Cancelled->value],
                actor: $actor,
            );

            return $requisition;
        });
    }
}
