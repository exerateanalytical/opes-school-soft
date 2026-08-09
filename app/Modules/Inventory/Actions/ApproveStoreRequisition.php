<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StoreRequisitionStatus;
use App\Modules\Inventory\Models\StoreRequisition;
use App\Modules\Inventory\Models\StoreRequisitionLine;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.8 - approval is a different hand than
 * the request (maker/checker), with per-line approved quantities capped
 * only by sense (non-negative), not by stock: availability is the ISSUE's
 * concern, under its own lock.
 */
final class ApproveStoreRequisition
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, string>  $approvedQuantities  item_id => quantity; missing items default to the requested quantity
     */
    public function handle(int $requisitionId, Actor $actor, array $approvedQuantities = [], bool $reject = false): StoreRequisition
    {
        Gate::authorize(InventoryPermission::POST);

        return DB::transaction(function () use ($requisitionId, $actor, $approvedQuantities, $reject): StoreRequisition {
            /** @var StoreRequisition|null $requisition */
            $requisition = StoreRequisition::query()->lockForUpdate()->find($requisitionId);

            if ($requisition === null) {
                throw new DomainException("Store requisition {$requisitionId} does not exist.");
            }

            if ($requisition->status !== StoreRequisitionStatus::Submitted) {
                throw new DomainException(
                    "Store requisition '{$requisition->requisition_no}' is {$requisition->status->value}; only a submitted requisition can be decided."
                );
            }

            if ($requisition->requested_by === $actor->id) {
                throw new DomainException(
                    'A store requisition cannot be approved by its own requester (maker/checker).'
                );
            }

            if (! $reject) {
                /** @var StoreRequisitionLine $line */
                foreach ($requisition->lines()->get() as $line) {
                    $approved = $approvedQuantities[$line->item_id] ?? $line->quantity_requested;

                    if (\App\Modules\Inventory\Domain\WeightedAverageCost::compare($approved, '0') < 0) {
                        throw new DomainException('An approved quantity cannot be negative.');
                    }

                    $line->forceFill(['quantity_approved' => $approved])->save();
                }
            }

            $requisition->forceFill([
                'status' => $reject ? StoreRequisitionStatus::Rejected : StoreRequisitionStatus::Approved,
                'approved_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                StoreRequisition::class,
                (int) $requisition->getKey(),
                ['status' => 'submitted'],
                ['status' => $reject ? 'rejected' : 'approved'],
                $actor,
            );

            return $requisition;
        });
    }
}
