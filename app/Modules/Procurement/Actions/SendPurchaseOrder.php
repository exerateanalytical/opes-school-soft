<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.2 - mark an approved PO as sent to
 * the supplier. Recording the moment matters: from the supplier's side this
 * is when the commitment was communicated, which is what a dispute cites.
 */
final class SendPurchaseOrder
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $purchaseOrderId, Actor $actor): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        return DB::transaction(function () use ($purchaseOrderId, $actor): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();

            if ($po->status !== PurchaseOrderStatus::Approved) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Only an approved purchase order can be sent; %s is %s.', $po->po_no, $po->status->value),
                ]);
            }

            $po->status = PurchaseOrderStatus::Sent;
            $po->sent_at = now();
            $po->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: ['status' => PurchaseOrderStatus::Sent->value],
                actor: $actor,
            );

            return $po;
        });
    }
}
