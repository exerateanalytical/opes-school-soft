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
 * docs/specs/06-assets-stores.md §7.7 - releasing (or marking consumed)
 * gives `quantity_reserved` back under the same row lock. The scheduled
 * expiry job walks active reservations past `expires_on` through this same
 * door - one writer, one invariant set.
 */
final class ReleaseReservation
{
    use MovesStock;

    public function __construct(private readonly WriteAuditEntry $audit) {}

    public function handle(int $reservationId, Actor $actor, bool $consumed = false): StockReservation
    {
        Gate::authorize(InventoryPermission::MANAGE);

        return DB::transaction(function () use ($reservationId, $actor, $consumed): StockReservation {
            /** @var StockReservation|null $reservation */
            $reservation = StockReservation::query()->lockForUpdate()->find($reservationId);

            if ($reservation === null) {
                throw new DomainException("Stock reservation {$reservationId} does not exist.");
            }

            if ($reservation->status !== ReservationStatus::Active) {
                return $reservation; // Idempotent: already released/consumed.
            }

            $this->lockLocation($reservation->store_location_id);
            $balance = $this->lockBalance($reservation->item_id, $reservation->store_location_id);

            $newReserved = WeightedAverageCost::subtract($balance->quantity_reserved, $reservation->quantity);

            if (WeightedAverageCost::compare($newReserved, '0') < 0) {
                $newReserved = '0.000'; // Defensive: never below zero (I7).
            }

            DB::table('stock_balances')
                ->where('item_id', $balance->item_id)
                ->where('store_location_id', $balance->store_location_id)
                ->update([
                    'quantity_reserved' => $newReserved,
                    'updated_at' => now(),
                ]);

            $reservation->forceFill([
                'status' => $consumed ? ReservationStatus::Consumed : ReservationStatus::Released,
            ])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                StockReservation::class,
                (int) $reservation->getKey(),
                ['status' => 'active'],
                ['status' => $consumed ? 'consumed' : 'released'],
                $actor,
            );

            return $reservation;
        });
    }
}
