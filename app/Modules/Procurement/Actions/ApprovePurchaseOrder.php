<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Models\ApprovalThreshold;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.2 - approve a PO, the moment it
 * becomes immutable (invariant 5) and a real commitment to a supplier.
 *
 * Threshold routing: the FIRST ApprovalThreshold band (by sequence)
 * containing `total_ttc` names the role the approver must HOLD - the
 * permission alone is not enough for a 5,000,000 FCFA order when the band
 * says Principal. No bands configured = permission-only approval; that is
 * the school's own policy choice, not a tax datum, so nothing blocks.
 *
 * Segregation of duties: the CREATOR cannot approve their own PO, and when
 * the PO consolidates a requisition, the original REQUESTER cannot either -
 * §4.2 states the pair explicitly and test obligation 14 covers it.
 */
final class ApprovePurchaseOrder
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $purchaseOrderId, Actor $actor): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_APPROVE);

        return DB::transaction(function () use ($purchaseOrderId, $actor): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();

            if (! $po->status->isPreApproval()) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Purchase order %s is already %s.', $po->po_no, $po->status->value),
                ]);
            }

            if ($actor->id !== null && $po->created_by === $actor->id) {
                throw ValidationException::withMessages([
                    'approved_by' => 'The creator cannot approve their own purchase order (03-tax-procurement 4.2, segregation of duties).',
                ]);
            }

            if ($actor->id !== null && $po->requisition_id !== null) {
                $requestedBy = DB::table('purchase_requisitions')->where('id', $po->requisition_id)->value('requested_by');

                if ((int) $requestedBy === $actor->id) {
                    throw ValidationException::withMessages([
                        'approved_by' => 'The requester cannot approve the purchase order raised from their own requisition (03-tax-procurement 4.2).',
                    ]);
                }
            }

            $band = ApprovalThreshold::bandFor($po->total_ttc);

            if ($band !== null && ! $this->actorHoldsRole($actor, $band->required_role)) {
                throw ValidationException::withMessages([
                    'approved_by' => sprintf(
                        'An order of %d FCFA requires approval by a holder of the [%s] role (03-tax-procurement 4.2 thresholds).',
                        $po->total_ttc,
                        $band->required_role,
                    ),
                ]);
            }

            $po->status = PurchaseOrderStatus::Approved;
            $po->approved_by = $actor->id;
            $po->approved_at = now();
            $po->version = $po->version + 1;
            $po->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: [
                    'status' => PurchaseOrderStatus::Approved->value,
                    'total_ttc' => $po->total_ttc,
                    'threshold_role' => $band?->required_role,
                ],
                actor: $actor,
            );

            return $po;
        });
    }

    /**
     * Role membership via the Spatie tables with the query builder - the
     * Actor value object deliberately carries no Identity model.
     */
    private function actorHoldsRole(Actor $actor, string $role): bool
    {
        if ($actor->id === null) {
            // Unattended actors (jobs, migrations) hold no role; a threshold
            // band therefore always refuses them, which is correct - a human
            // approves commitments.
            return false;
        }

        return DB::table('model_has_roles')
            ->join('roles', 'roles.id', '=', 'model_has_roles.role_id')
            ->where('model_has_roles.model_id', $actor->id)
            ->where('model_has_roles.model_type', 'App\\Modules\\Identity\\Models\\User')
            ->where('roles.name', $role)
            ->exists();
    }
}
