<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Accounting\Actions\PostFromEvent;
use App\Modules\Accounting\Domain\PostingEvent;
use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StockMovementType;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * A standalone signed stock adjustment OUTSIDE a stock take (damage,
 * write-off, found stock) - a mini-variance with a mandatory reason.
 *
 * Costing follows §7.1 exactly: a negative adjustment consumes at the
 * current derived average (empty-bin rule included) and does not move the
 * average; a positive adjustment is priced at the current derived cost -
 * when the bin is empty there is no cost basis, so an explicit
 * `total_cost` is REQUIRED then (and only then accepted).
 *
 * Ledger: outflow posts `inventory.written_off` (Dr 603x-or-loss / Cr 3x
 * per the school's rule); inflow posts `inventory.received_into_stock`.
 * V16 (658-family loss routing) stays with the posting-rule configuration.
 */
final class AdjustStock
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     item_id: int,
     *     store_location_id: int,
     *     quantity: string,
     *     direction: 'in'|'out',
     *     reason: string,
     *     moved_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     total_cost?: int|null,
     *     idempotency_key?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): StockMovement
    {
        Gate::authorize(InventoryPermission::POST);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = StockMovement::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if (trim($data['reason']) === '') {
            throw new DomainException('A stock adjustment needs a stated reason.');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): StockMovement {
            $item = $this->itemWithAccounts($data['item_id'], ['stock', 'variation']);
            $this->lockLocation($data['store_location_id']);
            $balance = $this->lockBalance($item->id, $data['store_location_id']);

            $out = $data['direction'] === 'out';

            if ($out) {
                $cost = WeightedAverageCost::issueCost(
                    $data['quantity'],
                    $balance->quantity_on_hand,
                    $balance->value_on_hand,
                );
            } elseif (WeightedAverageCost::isZero($balance->quantity_on_hand)) {
                $cost = $data['total_cost'] ?? null;

                if ($cost === null || $cost < 0) {
                    throw new DomainException(
                        'A positive adjustment onto an empty bin has no derived cost basis; state total_cost explicitly.'
                    );
                }
            } else {
                if (($data['total_cost'] ?? null) !== null) {
                    throw new DomainException(
                        'A positive adjustment onto a non-empty bin is priced at the current derived cost (§8.4); total_cost is not an input.'
                    );
                }

                // Derived-cost pricing: qty x (value / on-hand), half-up.
                $cost = WeightedAverageCost::varianceValue(
                    $data['quantity'],
                    $balance->quantity_on_hand,
                    $balance->value_on_hand,
                );
            }

            $reference = sprintf('ADJ/%s/%s', $data['moved_on'], $item->item_code);

            $entry = $this->post->handle(
                $out ? PostingEvent::InventoryWrittenOff->value : PostingEvent::InventoryReceivedIntoStock->value,
                [
                    'movement' => [
                        'amount' => $cost,
                        'reference' => $reference,
                        'stock_account_id' => (int) $item->stock_account_id,
                        'variation_account_id' => (int) $item->variation_account_id,
                    ],
                ],
                $data['moved_on'],
                $actor,
                $reference,
            );

            $movement = $this->applyMovement(
                $balance,
                $out ? StockMovementType::AdjustmentOut : StockMovementType::AdjustmentIn,
                $out ? '-'.$data['quantity'] : $data['quantity'],
                $out ? -$cost : $cost,
                $data['moved_on'],
                $data['fiscal_year_id'],
                $data['academic_year_id'],
                $actor,
                [
                    'journal_entry_id' => (int) $entry->getKey(),
                    'document_ref' => $reference,
                    'idempotency_key' => $idempotencyKey,
                ],
            );

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockMovement::class,
                (int) $movement->getKey(),
                null,
                [
                    'movement_type' => $out ? 'adjustment_out' : 'adjustment_in',
                    'item_id' => $item->id,
                    'quantity' => $data['quantity'],
                    'total_cost' => $cost,
                    'reason' => $data['reason'],
                ],
                $actor,
            );

            return $movement;
        });
    }
}
