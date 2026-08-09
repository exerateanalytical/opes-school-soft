<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\ReservationStatus;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockReservation;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.7 - a reservation holds NO cost and
 * posts NOTHING: it only moves `quantity_reserved` under the balance row
 * lock (I7 keeps it inside what is physically there). One active
 * reservation per (holder, item, location) - the generated NULL-unique key
 * is the DB backstop.
 */
final class ReserveStock
{
    use MovesStock;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param array{
     *     item_id: int,
     *     store_location_id: int,
     *     quantity: string,
     *     reserved_for_type: string,
     *     reserved_for_id: int,
     *     expires_on?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): StockReservation
    {
        Gate::authorize(InventoryPermission::MANAGE);

        return DB::transaction(function () use ($data, $actor): StockReservation {
            // Archived/untracked refusals ride on the shared loader; no
            // account is needed - nothing posts.
            $this->itemWithAccounts($data['item_id'], []);
            $this->lockLocation($data['store_location_id']);
            $balance = $this->lockBalance($data['item_id'], $data['store_location_id']);

            $available = WeightedAverageCost::subtract($balance->quantity_on_hand, $balance->quantity_reserved);

            if (WeightedAverageCost::compare($data['quantity'], $available) > 0) {
                throw new DomainException(sprintf(
                    'Cannot reserve %s: only %s available (%s on hand, %s already reserved) - I7.',
                    $data['quantity'],
                    $available,
                    $balance->quantity_on_hand,
                    $balance->quantity_reserved,
                ));
            }

            DB::table('stock_balances')
                ->where('item_id', $balance->item_id)
                ->where('store_location_id', $balance->store_location_id)
                ->update([
                    'quantity_reserved' => WeightedAverageCost::add($balance->quantity_reserved, $data['quantity']),
                    'updated_at' => now(),
                ]);

            $reservation = StockReservation::query()->create([
                'item_id' => $data['item_id'],
                'store_location_id' => $data['store_location_id'],
                'quantity' => $data['quantity'],
                'reserved_for_type' => $data['reserved_for_type'],
                'reserved_for_id' => $data['reserved_for_id'],
                'reserved_by' => $actor->id,
                'expires_on' => $data['expires_on'] ?? null,
                'status' => ReservationStatus::Active,
            ]);

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockReservation::class,
                (int) $reservation->getKey(),
                null,
                [
                    'item_id' => $data['item_id'],
                    'store_location_id' => $data['store_location_id'],
                    'quantity' => $data['quantity'],
                ],
                $actor,
            );

            return $reservation;
        });
    }
}
