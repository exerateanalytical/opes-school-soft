<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StoreRequisitionStatus;
use App\Modules\Inventory\Models\StoreRequisition;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.8 - the internal-consumption analogue
 * of a purchase requisition: who is asking, for which section/department,
 * for what. This is what makes the issue's analytic split defensible.
 */
final class CreateStoreRequisition
{
    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     lines: list<array{item_id: int, quantity: string}>,
     *     school_section_id?: int|null,
     *     department?: string|null,
     *     needed_on?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): StoreRequisition
    {
        Gate::authorize(InventoryPermission::MANAGE);

        if ($data['lines'] === []) {
            throw new DomainException('A store requisition needs at least one line.');
        }

        return DB::transaction(function () use ($data, $actor): StoreRequisition {
            $year = Carbon::now()->format('Y');
            $number = sprintf('SRQ/%s/%06d', $year, $this->sequence->allocate('store_requisition_no.'.$year));

            $requisition = StoreRequisition::query()->create([
                'requisition_no' => $number,
                'school_section_id' => $data['school_section_id'] ?? null,
                'department' => $data['department'] ?? null,
                'requested_by' => $actor->id,
                'status' => StoreRequisitionStatus::Submitted,
                'needed_on' => $data['needed_on'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $requisition->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity_requested' => $line['quantity'],
                ]);
            }

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StoreRequisition::class,
                (int) $requisition->getKey(),
                null,
                ['requisition_no' => $number, 'lines' => count($data['lines'])],
                $actor,
            );

            return $requisition;
        });
    }
}
