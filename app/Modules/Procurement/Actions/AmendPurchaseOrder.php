<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\LineAmount;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Models\PurchaseOrder;
use App\Modules\Procurement\Models\PurchaseOrderAmendment;
use App\Modules\Procurement\Models\PurchaseOrderLine;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.2 invariant 5 - THE change path for
 * an approved PO. The prior line set is snapshotted into
 * `purchase_order_amendments` FIRST (so history replays), then the rewrite
 * runs inside PurchaseOrder::withinAmendmentWindow - the only door through
 * the observer's freeze.
 *
 * Optimistic lock: the caller states the `version` it examined; a
 * concurrent amendment bumps it and this one refuses (00-core §10.6).
 *
 * Lines that goods receipts or invoices already reference cannot be
 * REMOVED (the RESTRICT FK refuses), and a line's quantity cannot be
 * amended BELOW what was already received - fulfilment is fact, the
 * amendment is intention.
 */
final class AmendPurchaseOrder
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  list<array{line_no?: int|null, description: string, quantity: string, unit_price_ht: int, discount_rate_bp?: int, tax_code_id?: int|null, expense_account_id: int, is_capitalised?: bool, unit_of_measure?: string|null}>  $lines  the COMPLETE new line set; line_no matches an existing line to keep, absent = new line
     */
    public function handle(int $purchaseOrderId, string $reason, array $lines, int $expectedVersion, Actor $actor): PurchaseOrder
    {
        Gate::authorize(ProcurementPermission::ORDER_APPROVE);

        if (trim($reason) === '') {
            throw ValidationException::withMessages([
                'reason' => 'An amendment must state its reason (03-tax-procurement 4.2 invariant 5).',
            ]);
        }

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'An amendment cannot leave the order without lines; cancel or close it instead.']);
        }

        return DB::transaction(function () use ($purchaseOrderId, $reason, $lines, $expectedVersion, $actor): PurchaseOrder {
            /** @var PurchaseOrder $po */
            $po = PurchaseOrder::query()->whereKey($purchaseOrderId)->lockForUpdate()->firstOrFail();

            if ($po->status->isPreApproval()) {
                throw ValidationException::withMessages([
                    'status' => 'A draft purchase order is edited directly; amendments exist for approved orders.',
                ]);
            }

            if (in_array($po->status->value, ['closed', 'cancelled', 'invoiced'], true)) {
                throw ValidationException::withMessages([
                    'status' => sprintf('Purchase order %s is %s and can no longer be amended.', $po->po_no, $po->status->value),
                ]);
            }

            if ($po->version !== $expectedVersion) {
                throw ValidationException::withMessages([
                    'version' => sprintf(
                        'Purchase order %s changed while you were editing (version %d, you examined %d) - reload and re-apply.',
                        $po->po_no,
                        $po->version,
                        $expectedVersion,
                    ),
                ]);
            }

            /** @var list<PurchaseOrderLine> $currentLines */
            $currentLines = $po->lines()->lockForUpdate()->get()->all();
            $byLineNo = [];

            foreach ($currentLines as $current) {
                $byLineNo[$current->line_no] = $current;
            }

            // The snapshot precedes any change - §4.2's "snapshot of the
            // prior line set".
            $amendmentNo = (int) $po->amendments()->max('amendment_no') + 1;

            PurchaseOrderAmendment::query()->create([
                'purchase_order_id' => $po->getKey(),
                'amendment_no' => $amendmentNo,
                'reason' => trim($reason),
                'previous_lines' => array_map(
                    static fn (PurchaseOrderLine $line): array => $line->only([
                        'line_no', 'description', 'quantity', 'unit_price_ht', 'discount_rate_bp',
                        'amount_ht', 'tax_code_id', 'tax_amount', 'amount_ttc', 'expense_account_id',
                        'is_capitalised', 'qty_received', 'qty_invoiced',
                    ]),
                    $currentLines,
                ),
                'previous_subtotal_ht' => $po->subtotal_ht,
                'previous_total_ttc' => $po->total_ttc,
                'amended_by' => $actor->id,
                'amended_at' => now(),
            ]);

            $result = PurchaseOrder::withinAmendmentWindow(function () use ($po, $lines, $byLineNo): PurchaseOrder {
                $keptLineNos = [];
                $subtotal = 0;
                $taxTotal = 0;
                $nextLineNo = $byLineNo === [] ? 0 : max(array_keys($byLineNo));

                foreach ($lines as $line) {
                    $amountHt = LineAmount::compute(
                        $line['quantity'],
                        $line['unit_price_ht'],
                        (int) ($line['discount_rate_bp'] ?? 0),
                    );
                    $taxCodeId = $line['tax_code_id'] ?? null;
                    $taxAmount = 0;

                    if ($taxCodeId !== null) {
                        $rateBp = DB::table('tax_codes')->where('id', (int) $taxCodeId)->value('rate_bp');
                        $taxAmount = $rateBp === null ? 0 : intdiv($amountHt * (int) $rateBp + 5_000, 10_000);
                    }

                    $attributes = [
                        'description' => $line['description'],
                        'quantity' => $line['quantity'],
                        'unit_of_measure' => $line['unit_of_measure'] ?? null,
                        'unit_price_ht' => $line['unit_price_ht'],
                        'discount_rate_bp' => (int) ($line['discount_rate_bp'] ?? 0),
                        'amount_ht' => $amountHt,
                        'tax_code_id' => $taxCodeId,
                        'tax_amount' => $taxAmount,
                        'amount_ttc' => $amountHt + $taxAmount,
                        'expense_account_id' => $line['expense_account_id'],
                        'is_capitalised' => (bool) ($line['is_capitalised'] ?? false),
                    ];

                    $existing = isset($line['line_no'])
                        ? ($byLineNo[$line['line_no']] ?? null)
                        : null;

                    if ($existing !== null) {
                        if (LineAmount::toMillis($line['quantity']) < LineAmount::toMillis($existing->qty_received)) {
                            throw ValidationException::withMessages([
                                'lines' => sprintf(
                                    'Line %d already received %s; the amended quantity %s cannot fall below it.',
                                    $existing->line_no,
                                    $existing->qty_received,
                                    $line['quantity'],
                                ),
                            ]);
                        }

                        $existing->fill($attributes);
                        $existing->save();
                        $keptLineNos[] = $existing->line_no;
                    } else {
                        $nextLineNo++;
                        PurchaseOrderLine::query()->create(
                            $attributes + ['purchase_order_id' => $po->getKey(), 'line_no' => $nextLineNo],
                        );
                        $keptLineNos[] = $nextLineNo;
                    }

                    $subtotal += $amountHt;
                    $taxTotal += $taxAmount;
                }

                foreach ($byLineNo as $lineNo => $current) {
                    if (! in_array($lineNo, $keptLineNos, true)) {
                        // RESTRICT FKs from receipts/invoices refuse this
                        // delete when the line has history - correctly.
                        $current->delete();
                    }
                }

                $po->subtotal_ht = $subtotal;
                $po->tax_total = $taxTotal;
                $po->total_ttc = $subtotal + $taxTotal;
                $po->version = $po->version + 1;
                $po->save();

                return $po;
            });

            $this->audit->handle(
                action: AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseOrder::class,
                auditableId: (int) $po->getKey(),
                after: [
                    'amendment_no' => $amendmentNo,
                    'reason' => trim($reason),
                    'total_ttc' => $po->total_ttc,
                    'version' => $po->version,
                ],
                actor: $actor,
            );

            return $result->refresh();
        });
    }
}
