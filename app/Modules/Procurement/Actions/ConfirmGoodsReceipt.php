<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Events\GoodsReceived;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\ProcurementSettings;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.3 - confirm a draft receipt: the
 * moment goods become FACT.
 *
 * Concurrency (§9): every referenced PO line is taken FOR UPDATE, the
 * over-receipt tolerance (invariant 3: qty_received <= quantity x
 * (1 + over_receipt_tolerance_bp/10000)) is checked INSIDE the lock, and
 * `qty_received` advances inside it too - two clerks receiving the same
 * delivery cannot both fit through the tolerance window.
 *
 * POSTS NOTHING. Recognition happens on the invoice or at year-end cut-off
 * via 4818 (§3.3); posting here would double-count when the invoice
 * arrives. The after-commit `procurement.goods.received` event is the
 * Phase 9 door for Inventory (StockMovement at PO cost) and Assets
 * (provisional pending_capitalisation asset); until those modules land the
 * recorded intent is the receipt lines' inventory/asset columns.
 */
final class ConfirmGoodsReceipt
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $goodsReceiptId, Actor $actor): GoodsReceipt
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        $receipt = DB::transaction(function () use ($goodsReceiptId, $actor): GoodsReceipt {
            /** @var GoodsReceipt $receipt */
            $receipt = GoodsReceipt::query()->whereKey($goodsReceiptId)->lockForUpdate()->firstOrFail();

            if ($receipt->status !== GoodsReceiptStatus::Draft) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Goods receipt %s is already %s.', $receipt->receipt_no, $receipt->status->value),
                ]);
            }

            $toleranceBp = ProcurementSettings::current()->over_receipt_tolerance_bp;

            /** @var list<GoodsReceiptLine> $lines */
            $lines = $receipt->lines()->get()->all();
            $hasDiscrepancy = false;
            $po = null;

            foreach ($lines as $line) {
                if (LineAmount::toMillis($line->qty_rejected) > 0) {
                    $hasDiscrepancy = true;
                }

                if ($line->purchase_order_line_id === null) {
                    continue;
                }

                // §9: FOR UPDATE on the PO line; tolerance check AND counter
                // update inside the lock.
                /** @var PurchaseOrderLine $poLine */
                $poLine = PurchaseOrderLine::query()
                    ->whereKey($line->purchase_order_line_id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $accepted = LineAmount::toMillis($line->qty_accepted);
                $already = LineAmount::toMillis($poLine->qty_received);
                $ordered = LineAmount::toMillis($poLine->quantity);
                $ceiling = intdiv($ordered * (10_000 + $toleranceBp), 10_000);

                if ($already + $accepted > $ceiling) {
                    throw ValidationException::withMessages([
                        'lines' => sprintf(
                            'Accepting %s on PO line %d would take received quantity past the ordered %s plus the %d bp tolerance (03-tax-procurement 4.2 invariant 3).',
                            $line->qty_accepted,
                            $poLine->line_no,
                            $poLine->quantity,
                            $toleranceBp,
                        ),
                    ]);
                }

                $newReceived = $already + $accepted;
                $poLine->qty_received = sprintf('%d.%03d', intdiv($newReceived, 1000), $newReceived % 1000);
                $poLine->save();
            }

            $receipt->has_discrepancy = $hasDiscrepancy;
            $receipt->status = GoodsReceiptStatus::Confirmed;
            $receipt->save();

            if ($receipt->purchase_order_id !== null) {
                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->whereKey($receipt->purchase_order_id)->lockForUpdate()->firstOrFail();

                $outstanding = $po->lines()->whereColumn('qty_received', '<', 'quantity')->exists();
                $po->status = $outstanding ? PurchaseOrderStatus::PartiallyReceived : PurchaseOrderStatus::Received;
                $po->save();
            }

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: GoodsReceipt::class,
                auditableId: (int) $receipt->getKey(),
                after: [
                    'receipt_no' => $receipt->receipt_no,
                    'status' => GoodsReceiptStatus::Confirmed->value,
                    'has_discrepancy' => $hasDiscrepancy,
                    'purchase_order_status' => $po?->status->value,
                ],
                actor: $actor,
            );

            return $receipt;
        });

        // After the commit only - a listener reacting to a receipt that then
        // rolled back would move stock that never arrived.
        GoodsReceived::dispatch(
            (int) $receipt->getKey(),
            $receipt->supplier_id,
            $receipt->purchase_order_id,
        );

        return $receipt;
    }
}
