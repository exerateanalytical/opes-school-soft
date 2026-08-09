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
 * docs/specs/03-tax-procurement.md §4.2 - close a PO. Short-closing an
 * under-delivered order (the supplier will never deliver the rest) demands
 * a stored reason; closing a fully-received one is routine housekeeping
 * that frees the "open commitments" report of noise.
 */
final class ClosePurchaseOrder
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $purchaseOrderId, Actor $actor, ?string $reason = null): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        return DB::transaction(function () use ($purchaseOrderId, $actor, $reason): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();

            $closable = in_array($po->status, [
                PurchaseOrderStatus::Approved,
                PurchaseOrderStatus::Sent,
                PurchaseOrderStatus::PartiallyReceived,
                PurchaseOrderStatus::Received,
                PurchaseOrderStatus::PartiallyInvoiced,
                PurchaseOrderStatus::Invoiced,
            ], true);

            if (! $closable) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Purchase order %s is %s and cannot be closed.', $po->po_no, $po->status->value),
                ]);
            }

            $underDelivered = $po->lines()->whereColumn('qty_received', '<', 'quantity')->exists();

            if ($underDelivered && trim((string) $reason) === '') {
                throw ValidationException::withMessages([
                    'closed_reason' => 'Short-closing an under-delivered order must state why (03-tax-procurement 4.2).',
                ]);
            }

            $po->status = PurchaseOrderStatus::Closed;
            $po->closed_reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;
            $po->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: ['status' => PurchaseOrderStatus::Closed->value, 'closed_reason' => $po->closed_reason],
                actor: $actor,
            );

            return $po;
        });
    }
}
