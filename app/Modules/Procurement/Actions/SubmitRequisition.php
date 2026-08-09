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
 * docs/specs/03-tax-procurement.md §4.1 - draft -> submitted, entering the
 * approval queue. From here the document can no longer be deleted (§9
 * trigger) or edited; it is approved, rejected or cancelled.
 */
final class SubmitRequisition
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $requisitionId, Actor $actor): PurchaseRequisition
    {
        Gate::authorize(ProcurementPermission::VIEW);

        return DB::transaction(function () use ($requisitionId, $actor): PurchaseRequisition {
            /** @var PurchaseRequisition $requisition */
            $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->lockForUpdate()->firstOrFail();

            if ($requisition->status !== RequisitionStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Requisition %s is already %s.', $requisition->requisition_no, $requisition->status->value),
                ]);
            }

            if (! $requisition->lines()->exists()) {
                throw ValidationException::withMessages([
                    'lines' => 'An empty requisition cannot be submitted.',
                ]);
            }

            $requisition->status = RequisitionStatus::Submitted;
            $requisition->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseRequisition::class,
                auditableId: (int) $requisition->getKey(),
                after: ['status' => RequisitionStatus::Submitted->value],
                actor: $actor,
            );

            return $requisition;
        });
    }
}
