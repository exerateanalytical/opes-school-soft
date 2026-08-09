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
 * docs/specs/03-tax-procurement.md §9 - cancel a PO that never happened
 * commercially. Blocked the moment ANYTHING was received or invoiced
 * against it: from then on the trail is closed (short-close with reason),
 * not erased.
 */
final class CancelPurchaseOrder
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $purchaseOrderId, Actor $actor): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        return DB::transaction(function () use ($purchaseOrderId, $actor): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();

            if (in_array($po->status, [PurchaseOrderStatus::Closed, PurchaseOrderStatus::Cancelled], true)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Purchase order %s is already %s.', $po->po_no, $po->status->value),
                ]);
            }

            $hasFulfilment = $po->lines()
                ->where(function ($query): void {
                    $query->where('qty_received', '>', 0)->orWhere('qty_invoiced', '>', 0);
                })
                ->exists();

            if ($hasFulfilment) {
                throw ValidationException::withMessages([
                    'status' => sprintf(
                        'Purchase order %s has receipts or invoices against it; short-close it with a reason instead of cancelling.',
                        $po->po_no,
                    ),
                ]);
            }

            $po->status = PurchaseOrderStatus::Cancelled;
            $po->save();

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: ['status' => PurchaseOrderStatus::Cancelled->value],
                actor: $actor,
            );

            return $po;
        });
    }
}
