<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\GoodsReceiptStatus;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\GoodsReceipt;
use App\Modules\Procurement\Models\GoodsReceiptLine;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.3 - record a DRAFT bon de reception,
 * against a PO or direct (where the PO step is not required). Nothing
 * advances on the PO until ConfirmGoodsReceipt runs - a draft receipt is a
 * clipboard, not a fact.
 *
 * qty_accepted + qty_rejected = qty_received per line (DB CHECK repeats
 * this); a rejection needs its reason, because the rejected quantity is
 * what later justifies the supplier credit note.
 */
final class SaveGoodsReceipt
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header  supplier_id, received_on, academic_year_id, fiscal_year_id, optional purchase_order_id/delivery_note_ref/store_location_id
     * @param  list<array{qty_received: string, qty_rejected?: string, description?: string|null, purchase_order_line_id?: int|null, rejection_reason?: string|null, inventory_item_id?: int|null, asset_category_id?: int|null, serial_numbers?: list<string>|null}>  $lines
     */
    public function handle(array $header, array $lines, Actor $actor): GoodsReceipt
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A goods receipt needs at least one line.']);
        }

        return DB::transaction(function () use ($header, $lines, $actor): GoodsReceipt {
            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail((int) ($header['supplier_id'] ?? 0));

            $po = null;

            if (isset($header['purchase_order_id'])) {
                /** @var PurchaseOrder $po */
                $po = PurchaseOrder::query()->findOrFail((int) $header['purchase_order_id']);

                if ($po->supplier_id !== $supplier->id) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => sprintf('%s belongs to a different supplier.', $po->po_no),
                    ]);
                }

                if (! $po->status->isReceivable()) {
                    throw ValidationException::withMessages([
                        'purchase_order_id' => sprintf(
                            'Purchase order %s is %s - goods can only be received against an approved or sent order.',
                            $po->po_no,
                            $po->status->value,
                        ),
                    ]);
                }
            }

            $receivedOn = (string) ($header['received_on'] ?? now()->toDateString());

            $receipt = GoodsReceipt::query()->create([
                'receipt_no' => sprintf('BR/%s/%06d', Carbon::parse($receivedOn)->format('Y'), $this->sequences->allocate('BR')),
                'purchase_order_id' => $po?->getKey(),
                'supplier_id' => $supplier->id,
                'received_on' => $receivedOn,
                'received_by' => $actor->id,
                'delivery_note_ref' => $header['delivery_note_ref'] ?? null,
                'store_location_id' => $header['store_location_id'] ?? null,
                'status' => GoodsReceiptStatus::Draft,
                'has_discrepancy' => false,
                'academic_year_id' => (int) ($header['academic_year_id'] ?? 0),
                'fiscal_year_id' => (int) ($header['fiscal_year_id'] ?? 0),
            ]);

            foreach ($lines as $index => $line) {
                $received = LineAmount::toMillis($line['qty_received']);
                $rejected = LineAmount::toMillis($line['qty_rejected'] ?? '0');

                if ($rejected > $received) {
                    throw ValidationException::withMessages([
                        'lines' => sprintf('Line %d rejects more than was received.', $index + 1),
                    ]);
                }

                if ($rejected > 0 && trim((string) ($line['rejection_reason'] ?? '')) === '') {
                    throw ValidationException::withMessages([
                        'lines' => sprintf('Line %d rejects goods without a reason (03-tax-procurement 4.3).', $index + 1),
                    ]);
                }

                $description = $line['description'] ?? null;
                $qtyOrdered = '0.000';
                $poLineId = $line['purchase_order_line_id'] ?? null;

                if ($poLineId !== null) {
                    /** @var object{purchase_order_id: int|string, description: string, quantity: string}|null $poLine */
                    $poLine = DB::table('purchase_order_lines')
                        ->where('id', (int) $poLineId)
                        ->first(['purchase_order_id', 'description', 'quantity']);

                    if ($poLine === null || $po === null || (int) $poLine->purchase_order_id !== (int) $po->getKey()) {
                        throw ValidationException::withMessages([
                            'lines' => sprintf('Line %d references a PO line outside the received order.', $index + 1),
                        ]);
                    }

                    $description ??= $poLine->description;
                    $qtyOrdered = $poLine->quantity;
                }

                if ($description === null || trim($description) === '') {
                    throw ValidationException::withMessages([
                        'lines' => sprintf('Line %d needs a description.', $index + 1),
                    ]);
                }

                $accepted = $received - $rejected;

                GoodsReceiptLine::query()->create([
                    'goods_receipt_id' => $receipt->getKey(),
                    'line_no' => $index + 1,
                    'purchase_order_line_id' => $poLineId,
                    'description' => $description,
                    'qty_ordered' => $qtyOrdered,
                    'qty_received' => $line['qty_received'],
                    'qty_accepted' => sprintf('%d.%03d', intdiv($accepted, 1000), $accepted % 1000),
                    'qty_rejected' => $line['qty_rejected'] ?? '0',
                    'rejection_reason' => $rejected > 0 ? trim((string) ($line['rejection_reason'] ?? '')) : null,
                    'inventory_item_id' => $line['inventory_item_id'] ?? null,
                    'asset_category_id' => $line['asset_category_id'] ?? null,
                    'serial_numbers' => $line['serial_numbers'] ?? null,
                ]);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: GoodsReceipt::class,
                auditableId: (int) $receipt->getKey(),
                after: [
                    'receipt_no' => $receipt->receipt_no,
                    'supplier_id' => $supplier->id,
                    'purchase_order_id' => $po?->getKey(),
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $receipt->refresh();
        });
    }
}
