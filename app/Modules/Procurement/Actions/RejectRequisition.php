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
 * docs/specs/03-tax-procurement.md §4.1 - reject a submitted requisition.
 * `rejected_reason` is MANDATORY: a rejection without a reason teaches the
 * requester nothing and leaves no audit trail of why spend was refused.
 */
final class RejectRequisition
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $requisitionId, string $reason, Actor $actor): PurchaseRequisition
    {
        Gate::authorize(ProcurementPermission::REQUISITION_APPROVE);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'rejected_reason' => 'A rejection must state its reason (03-tax-procurement 4.1).',
            ]);
        }

        return DB::transaction(function () use ($requisitionId, $reason, $actor): PurchaseRequisition {
            /** @var PurchaseRequisition $requisition */
            $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== RequisitionStatus::Submitted) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Only a submitted requisition can be rejected; %s is %s.', $requisition->requisition_no, $requisition->status->value),
                ]);
            }

            $requisition->status = RequisitionStatus::Rejected;
            $requisition->rejected_reason = trim($reason);
            $requisition->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseRequisition::class,
                auditableId: (int) $requisition->getKey(),
                after: ['status' => RequisitionStatus::Rejected->value, 'rejected_reason' => trim($reason)],
                actor: $actor,
            );

            return $requisition;
        });
    }
}
