<?php

declare(strict_types=1);

namespace App\Modules\Procurement\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Procurement\Domain\ProcurementPermission;
use App\Modules\Procurement\Domain\RequisitionStatus;
use App\Modules\Procurement\Models\PurchaseRequisition;
use App\Modules\Procurement\Models\PurchaseRequisitionLine;
use App\Support\Audit\Actor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * docs/specs/03-tax-procurement.md §4.1 - create or rewrite a DRAFT
 * purchase requisition with its lines.
 *
 * Gated `procurement.view` deliberately: a requisition is a REQUEST - any
 * staff member may draft one; the controls sit on approval (SoD, budget,
 * thresholds), not on asking. `estimated_total` is derived from the lines
 * here and stored for indexing; line amounts are whole FCFA
 * (estimated_unit_price x quantity through LineAmount, rounded once).
 */
final class SaveRequisition
{
    public function __construct(
        private readonly \App\Support\Sequence\SequenceAllocator $sequences,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param  array<string, mixed>  $header
     * @param  list<array{description: string, quantity: string, estimated_unit_price: int, expense_account_id: int, unit_of_measure?: string|null, inventory_item_id?: int|null, asset_category_id?: int|null}>  $lines
     */
    public function handle(array $header, array $lines, Actor $actor, ?int $requisitionId = null): PurchaseRequisition
    {
        Gate::authorize(ProcurementPermission::VIEW);

        if ($lines === []) {
            throw ValidationException::withMessages([
                'lines' => 'A requisition needs at least one line.',
            ]);
        }

        return DB::transaction(function () use ($header, $lines, $actor, $requisitionId): PurchaseRequisition {
            unset($header['requisition_no'], $header['status'], $header['approved_by'], $header['approved_at']);

            if ($requisitionId === null) {
                if (isset($header['idempotency_key']) && is_string($header['idempotency_key'])) {
                    /** @var PurchaseRequisition|null $existing */
                    $existing = PurchaseRequisition::query()
                        ->where('idempotency_key', $header['idempotency_key'])
                        ->first();

                    if ($existing !== null) {
                        return $existing;
                    }
                }

                $header['requisition_no'] = sprintf(
                    'REQ/%s/%06d',
                    Carbon::parse((string) ($header['requested_on'] ?? now()->toDateString()))->format('Y'),
                    $this->sequences->allocate('REQ'),
                );
                $header['requested_by'] = $actor->id;
                $header['status'] = RequisitionStatus::Draft;

                /** @var PurchaseRequisition $requisition */
                $requisition = PurchaseRequisition::query()->create($header);
            } else {
                /** @var PurchaseRequisition $requisition */
                $requisition = PurchaseRequisition::query()->whereKey($requisitionId)->lockForUpdate()->firstOrFail();

                if ($requisition->status !== RequisitionStatus::Draft) {
                    throw ValidationException::withMessages([
                        'status' => 'Only a draft requisition can be edited; a submitted one is approved, rejected or cancelled.',
                    ]);
                }

                $requisition->fill($header);
                $requisition->save();
                $requisition->lines()->delete();
            }

            $total = 0;

            foreach ($lines as $index => $line) {
                $amount = \App\Modules\Procurement\Domain\LineAmount::compute(
                    $line['quantity'],
                    $line['estimated_unit_price'],
                );
                $total += $amount;

                PurchaseRequisitionLine::query()->create([
                    'requisition_id' => $requisition->getKey(),
                    'line_no' => $index + 1,
                    'description' => $line['description'],
                    'inventory_item_id' => $line['inventory_item_id'] ?? null,
                    'asset_category_id' => $line['asset_category_id'] ?? null,
                    'quantity' => $line['quantity'],
                    'unit_of_measure' => $line['unit_of_measure'] ?? null,
                    'estimated_unit_price' => $line['estimated_unit_price'],
                    'estimated_amount' => $amount,
                    'expense_account_id' => $line['expense_account_id'],
                ]);
            }

            $requisition->estimated_total = $total;
            $requisition->save();

            $this->audit->handle(
                action: $requisitionId === null ? AuditAction::Created : AuditAction::Updated,
                module: 'Procurement',
                auditableType: PurchaseRequisition::class,
                auditableId: (int) $requisition->getKey(),
                after: [
                    'requisition_no' => $requisition->requisition_no,
                    'estimated_total' => $total,
                    'lines' => count($lines),
                ],
                actor: $actor,
            );

            return $requisition->refresh();
        });
    }
}
