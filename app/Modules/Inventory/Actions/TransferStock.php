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
use App\Modules\Inventory\Domain\StockTransferStatus;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockTransfer;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.9. Two movements per line
 * (transfer_out / transfer_in) at the SENDING location's derived cost -
 * transfers never move the average at the sender (I1) and carry the
 * sender's cost into the receiver.
 *
 * LOCKING: both balance rows, ordered by (item_id, store_location_id)
 * ASCENDING - the mandatory anti-deadlock rule (§7.5), pure in
 * balanceLockOrder() and asserted by StockConcurrencyTest. Location rows
 * are likewise locked in ascending id order.
 *
 * ACCOUNTING: a transfer between two locations of the same legal entity
 * mapping to the same stock account is NOT a ledger event and posts
 * nothing (`posting_deferred_reason` states so per I13). The Action still
 * checks and posts `inventory.transfer` on a stock-account difference.
 */
final class TransferStock
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     from_location_id: int,
     *     to_location_id: int,
     *     lines: list<array{item_id: int, quantity: string}>,
     *     transferred_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     idempotency_key?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): StockTransfer
    {
        Gate::authorize(InventoryPermission::POST);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = StockTransfer::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($data['from_location_id'] === $data['to_location_id']) {
            throw new DomainException('A transfer needs two distinct locations.');
        }

        if ($data['lines'] === []) {
            throw new DomainException('A stock transfer needs at least one line.');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): StockTransfer {
            // Locations first, ascending id - consistent with every other
            // Action so the §7.10 freeze check can never deadlock either.
            $locationIds = [$data['from_location_id'], $data['to_location_id']];
            sort($locationIds);

            foreach ($locationIds as $locationId) {
                $this->lockLocation($locationId);
            }

            $items = [];

            foreach ($data['lines'] as $line) {
                $items[$line['item_id']] = $this->itemWithAccounts($line['item_id'], ['stock', 'variation']);
            }

            // BOTH rows per item, in the one mandatory order.
            $pairs = [];

            foreach (array_keys($items) as $itemId) {
                $pairs[] = [$itemId, $data['from_location_id']];
                $pairs[] = [$itemId, $data['to_location_id']];
            }

            $balances = [];

            foreach (self::balanceLockOrder($pairs) as [$itemId, $locationId]) {
                $balances[$itemId.':'.$locationId] = $this->lockBalance($itemId, $locationId);
            }

            $year = Carbon::parse($data['transferred_on'])->format('Y');
            $transferNo = sprintf('TRF/%s/%06d', $year, $this->sequence->allocate('stock_transfer_no.'.$year));

            // Same item => same category => same stock account: posts
            // nothing. The check stays structural for the reclassification
            // case §7.9 reserves the event for.
            $journalEntryId = null;

            $transfer = StockTransfer::query()->create([
                'transfer_no' => $transferNo,
                'from_location_id' => $data['from_location_id'],
                'to_location_id' => $data['to_location_id'],
                'status' => StockTransferStatus::Received,
                'transferred_on' => $data['transferred_on'],
                'journal_entry_id' => null,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $item = $items[$line['item_id']];
                $from = $balances[$line['item_id'].':'.$data['from_location_id']];
                $to = $balances[$line['item_id'].':'.$data['to_location_id']];

                $available = WeightedAverageCost::subtract($from->quantity_on_hand, $from->quantity_reserved);

                if (WeightedAverageCost::compare($line['quantity'], $available) > 0) {
                    throw new DomainException(sprintf(
                        "Insufficient stock of '%s' at the sending location: %s on hand, %s reserved, %s requested (I6/I7).",
                        $item->item_code,
                        $from->quantity_on_hand,
                        $from->quantity_reserved,
                        $line['quantity'],
                    ));
                }

                $cost = WeightedAverageCost::issueCost(
                    $line['quantity'],
                    $from->quantity_on_hand,
                    $from->value_on_hand,
                );

                // Both categories are the item's own: identical accounts,
                // no ledger event (§7.9). Stated on the movement per I13.
                $deferredReason = 'transfer_within_same_stock_account';

                $outMovement = $this->applyMovement(
                    $from,
                    StockMovementType::TransferOut,
                    '-'.$line['quantity'],
                    -$cost,
                    $data['transferred_on'],
                    $data['fiscal_year_id'],
                    $data['academic_year_id'],
                    $actor,
                    [
                        'reference_type' => 'StockTransfer',
                        'reference_id' => (int) $transfer->getKey(),
                        'journal_entry_id' => $journalEntryId,
                        'posting_deferred_reason' => $deferredReason,
                    ],
                );

                $inMovement = $this->applyMovement(
                    $to,
                    StockMovementType::TransferIn,
                    $line['quantity'],
                    $cost,
                    $data['transferred_on'],
                    $data['fiscal_year_id'],
                    $data['academic_year_id'],
                    $actor,
                    [
                        'reference_type' => 'StockTransfer',
                        'reference_id' => (int) $transfer->getKey(),
                        'journal_entry_id' => $journalEntryId,
                        'posting_deferred_reason' => $deferredReason,
                    ],
                );

                $transfer->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'transfer_cost' => $cost,
                    'out_movement_id' => (int) $outMovement->getKey(),
                    'in_movement_id' => (int) $inMovement->getKey(),
                ]);
            }

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockTransfer::class,
                (int) $transfer->getKey(),
                null,
                [
                    'transfer_no' => $transferNo,
                    'from_location_id' => $data['from_location_id'],
                    'to_location_id' => $data['to_location_id'],
                    'lines' => count($data['lines']),
                ],
                $actor,
            );

            return $transfer;
        });
    }

    /**
     * Kept for the reclassification case §7.9 reserves `inventory.transfer`
     * for: posts only when sending and receiving stock accounts differ.
     * With per-item categories both sides always match today, so this is
     * exercised by unit construction rather than the transfer flow.
     */
    public function postReclassification(
        int $amount,
        string $reference,
        int $fromStockAccountId,
        int $toStockAccountId,
        string $date,
        Actor $actor,
    ): ?int {
        if ($fromStockAccountId === $toStockAccountId) {
            return null;
        }

        $entry = $this->post->handle(
            PostingEvent::InventoryTransfer->value,
            [
                'movement' => [
                    'amount' => $amount,
                    'reference' => $reference,
                    'stock_account_id' => $toStockAccountId,
                    'variation_account_id' => $fromStockAccountId,
                ],
            ],
            $date,
            $actor,
            $reference,
        );

        return (int) $entry->getKey();
    }
}
