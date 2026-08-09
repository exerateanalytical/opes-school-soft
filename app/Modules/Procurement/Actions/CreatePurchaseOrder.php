<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\PurchaseOrderStatus;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\PurchaseRequisitionLine;
use App\Modules\Procurement\Models\Supplier;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.2 - create a DRAFT bon de commande,
 * blank or from an approved requisition.
 *
 * Line amounts follow invariant 1 (LineAmount, rounded once); the header
 * is a pure sum (invariant 2). Tax on a PO is indicative - the snapshot
 * that matters for the ledger is taken at invoice posting - so the line's
 * `tax_amount` is computed from the tax_code's current rate via a
 * cross-module DB::table read (never the Tax models), defaulting to 0
 * without a code.
 *
 * When ordering from a requisition, `qty_ordered` on its lines advances
 * under FOR UPDATE and the requisition flips to partially_ordered/ordered -
 * two clerks consolidating the same requisition cannot double-order a line.
 *
 * A PO POSTS NOTHING (invariant 6).
 */
final class CreatePurchaseOrder
{
    public function __construct(
        private readonly SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header  supplier_id, order_date, academic_year_id, fiscal_year_id, optional requisition_id/payable_account_id/retention/delivery fields
     * @param  list<array{description: string, quantity: string, unit_price_ht: int, discount_rate_bp?: int, tax_code_id?: int|null, expense_account_id?: int|null, requisition_line_id?: int|null, is_capitalised?: bool, inventory_item_id?: int|null, asset_category_id?: int|null, unit_of_measure?: string|null}>  $lines
     */
    public function handle(array $header, array $lines, Actor $actor): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_MANAGE);

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'A purchase order needs at least one line.']);
        }

        return DB::transaction(function () use ($header, $lines, $actor): PurchaseOrder {
            if (isset($header['idempotency_key']) && is_string($header['idempotency_key'])) {
                /** @var PurchaseOrder|null $existing */
                $existing = PurchaseOrder::query()->where('idempotency_key', $header['idempotency_key'])->first();

                if ($existing !== null) {
                    return $existing;
                }
            }

            /** @var Supplier $supplier */
            $supplier = Supplier::query()->findOrFail((int) ($header['supplier_id'] ?? 0));

            if ($supplier->is_archived || ! $supplier->is_active) {
                throw ValidationException::withMessages([
                    'supplier_id' => sprintf('Supplier %s is archived or blocked and cannot take new orders.', $supplier->code),
                ]);
            }

            $requisition = null;

            if (isset($header['requisition_id'])) {
                /** @var PurchaseRequisition $requisition */
                $requisition = PurchaseRequisition::query()
                    ->whereKey((int) $header['requisition_id'])
                    ->lockForUpdate()
                    ->firstOrFail();

                if (! $requisition->status->isOrderable()) {
                    throw ValidationException::withMessages([
                        'requisition_id' => sprintf(
                            'Requisition %s is %s - only an approved requisition can be ordered against.',
                            $requisition->requisition_no,
                            $requisition->status->value,
                        ),
                    ]);
                }
            }

            $orderDate = (string) ($header['order_date'] ?? now()->toDateString());

            $po = new PurchaseOrder([
                'po_no' => sprintf('BC/%s/%06d', Carbon::parse($orderDate)->format('Y'), $this->sequences->allocate('BC')),
                'supplier_id' => $supplier->id,
                'requisition_id' => $requisition?->getKey(),
                'order_date' => $orderDate,
                'expected_delivery_date' => $header['expected_delivery_date'] ?? null,
                'delivery_address' => $header['delivery_address'] ?? null,
                'currency' => (string) ($header['currency'] ?? 'XAF'),
                'exchange_rate_bp' => $header['exchange_rate_bp'] ?? null,
                'retention_rate_bp' => (int) ($header['retention_rate_bp'] ?? 0),
                'retention_release_due_on' => $header['retention_release_due_on'] ?? null,
                'status' => PurchaseOrderStatus::Draft,
                'created_by' => $actor->id,
                'payable_account_id' => (int) ($header['payable_account_id'] ?? $supplier->payable_account_id),
                'academic_year_id' => (int) ($header['academic_year_id'] ?? 0),
                'fiscal_year_id' => (int) ($header['fiscal_year_id'] ?? 0),
                'idempotency_key' => $header['idempotency_key'] ?? null,
            ]);
            $po->save();

            $subtotal = 0;
            $taxTotal = 0;

            foreach ($lines as $index => $line) {
                $amountHt = LineAmount::compute(
                    $line['quantity'],
                    $line['unit_price_ht'],
                    (int) ($line['discount_rate_bp'] ?? 0),
                );

                $taxCodeId = $line['tax_code_id'] ?? null;
                $taxAmount = $taxCodeId === null ? 0 : $this->indicativeTax($amountHt, (int) $taxCodeId);

                $expenseAccountId = $line['expense_account_id']
                    ?? $this->expenseAccountFromRequisitionLine($line['requisition_line_id'] ?? null)
                    ?? $supplier->default_expense_account_id;

                if ($expenseAccountId === null) {
                    throw ValidationException::withMessages([
                        'lines' => sprintf('Line %d needs an expense account - none given and the supplier has no default.', $index + 1),
                    ]);
                }

                PurchaseOrderLine::query()->create([
                    'purchase_order_id' => $po->getKey(),
                    'line_no' => $index + 1,
                    'requisition_line_id' => $line['requisition_line_id'] ?? null,
                    'description' => $line['description'],
                    'inventory_item_id' => $line['inventory_item_id'] ?? null,
                    'asset_category_id' => $line['asset_category_id'] ?? null,
                    'is_capitalised' => (bool) ($line['is_capitalised'] ?? false),
                    'quantity' => $line['quantity'],
                    'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'unit_price_ht' => $line['unit_price_ht'],
                    'discount_rate_bp' => (int) ($line['discount_rate_bp'] ?? 0),
                    'amount_ht' => $amountHt,
                    'tax_code_id' => $taxCodeId,
                    'tax_amount' => $taxAmount,
                    'amount_ttc' => $amountHt + $taxAmount,
                    'expense_account_id' => (int) $expenseAccountId,
                ]);

                $subtotal += $amountHt;
                $taxTotal += $taxAmount;

                if (isset($line['requisition_line_id'])) {
                    $this->advanceRequisitionLine((int) $line['requisition_line_id'], $line['quantity'], $requisition);
                }
            }

            // Invariant 2: the header is a SUM, never independently rounded.
            $po->subtotal_ht = $subtotal;
            $po->tax_total = $taxTotal;
            $po->total_ttc = $subtotal + $taxTotal;
            $po->save();

            if ($requisition !== null) {
                $this->refreshRequisitionStatus($requisition);
            }

            $this->audit->handle(
                action: AuditAction::Created,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: [
                    'po_no' => $po->po_no,
                    'supplier_id' => $supplier->id,
                    'total_ttc' => $po->total_ttc,
                    'requisition_id' => $requisition?->getKey(),
                ],
                actor: $actor,
            );

            return $po->refresh();
        });
    }

    /**
     * Indicative PO tax from the code's current rate - a cross-module READ
     * via the query builder (00-core 6.2), because the Tax models belong to
     * the Tax module. Whole-FCFA half-up, one rounding per line.
     */
    private function indicativeTax(int $amountHt, int $taxCodeId): int
    {
        $rateBp = DB::table('tax_codes')->where('id', $taxCodeId)->value('rate_bp');

        if ($rateBp === null) {
            throw ValidationException::withMessages([
                'lines' => sprintf('Tax code %d does not exist.', $taxCodeId),
            ]);
        }

        return intdiv($amountHt * (int) $rateBp + 5_000, 10_000);
    }

    private function expenseAccountFromRequisitionLine(?int $requisitionLineId): ?int
    {
        if ($requisitionLineId === null) {
            return null;
        }

        $value = DB::table('purchase_requisition_lines')->where('id', $requisitionLineId)->value('expense_account_id');

        return $value === null ? null : (int) $value;
    }

    /**
     * FOR UPDATE on the requisition line, then over-ordering is checked and
     * `qty_ordered` advanced INSIDE the lock (§9 concurrency discipline).
     */
    private function advanceRequisitionLine(int $requisitionLineId, string $quantity, ?PurchaseRequisition $requisition): void
    {
        /** @var PurchaseRequisitionLine $reqLine */
        $reqLine = PurchaseRequisitionLine::query()->whereKey($requisitionLineId)->lockForUpdate()->firstOrFail();

        if ($requisition === null || $reqLine->requisition_id !== (int) $requisition->getKey()) {
            throw ValidationException::withMessages([
                'lines' => sprintf('Requisition line %d does not belong to the named requisition.', $requisitionLineId),
            ]);
        }

        $newOrdered = LineAmount::toMillis($reqLine->qty_ordered) + LineAmount::toMillis($quantity);

        if ($newOrdered > LineAmount::toMillis($reqLine->quantity)) {
            throw ValidationException::withMessages([
                'lines' => sprintf(
                    'Ordering %s against requisition line %d would exceed its requested quantity %s.',
                    $quantity,
                    $reqLine->line_no,
                    $reqLine->quantity,
                ),
            ]);
        }

        $reqLine->qty_ordered = sprintf('%d.%03d', intdiv($newOrdered, 1000), $newOrdered % 1000);
        $reqLine->save();
    }

    private function refreshRequisitionStatus(PurchaseRequisition $requisition): void
    {
        $fullyOrdered = ! $requisition->lines()
            ->whereColumn('qty_ordered', '<', 'quantity')
            ->exists();

        $requisition->status = $fullyOrdered ? RequisitionStatus::Ordered : RequisitionStatus::PartiallyOrdered;
        $requisition->save();
    }
}
