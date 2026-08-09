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
use App\Modules\Inventory\Models\StockIssue;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §8.3 - the operation v1 could not post.
 * Issues consume at the CURRENT derived average and leave it unchanged
 * (I1); the empty-bin rule makes the last issue absorb the residual value
 * so I8 always holds.
 *
 * ONE JournalEntry per issue header (§7.8): Dr variation (603x) / Cr stock
 * (3x) for the header total, via `inventory.issued`. Because the posting
 * payload carries a single stock/variation pair, every line on one issue
 * must share its category's account pair - mixed-account consumption is
 * two issues, stated loudly rather than silently split.
 *
 * I5: discontinued items ISSUE freely (run the stock down); archived never.
 */
final class IssueStock
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     store_location_id: int,
     *     lines: list<array{item_id: int, quantity: string}>,
     *     issued_on: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     *     issued_to_staff_id?: int|null,
     *     store_requisition_id?: int|null,
     *     idempotency_key?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): StockIssue
    {
        Gate::authorize(InventoryPermission::POST);

        $idempotencyKey = $data['idempotency_key'] ?? null;

        if ($idempotencyKey !== null) {
            $existing = StockIssue::query()->where('idempotency_key', $idempotencyKey)->first();

            if ($existing !== null) {
                return $existing;
            }
        }

        if ($data['lines'] === []) {
            throw new DomainException('A stock issue needs at least one line.');
        }

        return DB::transaction(function () use ($data, $actor, $idempotencyKey): StockIssue {
            $locationId = $data['store_location_id'];
            $this->lockLocation($locationId);

            // Resolve every item first: I2/I5 refusals before any lock or
            // number is consumed, and the single-account-pair rule.
            $items = [];
            $stockAccountId = null;
            $variationAccountId = null;

            foreach ($data['lines'] as $line) {
                $item = $this->itemWithAccounts($line['item_id'], ['stock', 'variation']);
                $items[$item->id] = $item;

                if ($stockAccountId === null) {
                    $stockAccountId = (int) $item->stock_account_id;
                    $variationAccountId = (int) $item->variation_account_id;

                    continue;
                }

                if ($stockAccountId !== (int) $item->stock_account_id
                    || $variationAccountId !== (int) $item->variation_account_id) {
                    throw new DomainException(
                        'All lines of one stock issue must share the same stock/variation account pair (one JournalEntry per header, §7.8); issue mixed categories as separate issues.'
                    );
                }
            }

            // Lock every balance row in the mandatory §7.5 order, then cost
            // each line at the locked position.
            $pairs = [];

            foreach (array_keys($items) as $itemId) {
                $pairs[] = [$itemId, $locationId];
            }

            $balances = [];

            foreach (self::balanceLockOrder($pairs) as [$itemId, $pairLocationId]) {
                $balances[$itemId] = $this->lockBalance($itemId, $pairLocationId);
            }

            $costs = [];
            $total = 0;

            foreach ($data['lines'] as $line) {
                $balance = $balances[$line['item_id']];
                $available = WeightedAverageCost::subtract($balance->quantity_on_hand, $balance->quantity_reserved);

                if (WeightedAverageCost::compare($line['quantity'], $available) > 0) {
                    $item = $items[$line['item_id']];

                    throw new DomainException(sprintf(
                        "Insufficient stock of '%s': %s on hand, %s reserved, %s requested (I6/I7).",
                        $item->item_code,
                        $balance->quantity_on_hand,
                        $balance->quantity_reserved,
                        $line['quantity'],
                    ));
                }

                $costs[$line['item_id']] = WeightedAverageCost::issueCost(
                    $line['quantity'],
                    $balance->quantity_on_hand,
                    $balance->value_on_hand,
                );
                $total += $costs[$line['item_id']];
            }

            $year = Carbon::parse($data['issued_on'])->format('Y');
            $issueNo = sprintf('ISS/%s/%06d', $year, $this->sequence->allocate('stock_issue_no.'.$year));

            // The ledger leg first (one JE per header): movements are
            // append-only, so journal_entry_id must ride on the insert.
            $entry = $this->post->handle(
                PostingEvent::InventoryIssued->value,
                [
                    'movement' => [
                        'amount' => $total,
                        'reference' => $issueNo,
                        'stock_account_id' => (int) $stockAccountId,
                        'variation_account_id' => (int) $variationAccountId,
                    ],
                ],
                $data['issued_on'],
                $actor,
                $issueNo,
            );

            $issue = StockIssue::query()->create([
                'issue_no' => $issueNo,
                'store_location_id' => $locationId,
                'issued_to_staff_id' => $data['issued_to_staff_id'] ?? null,
                'store_requisition_id' => $data['store_requisition_id'] ?? null,
                'issued_on' => $data['issued_on'],
                'journal_entry_id' => (int) $entry->getKey(),
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'notes' => $data['notes'] ?? null,
            ]);

            foreach ($data['lines'] as $line) {
                $cost = $costs[$line['item_id']];

                $movement = $this->applyMovement(
                    $balances[$line['item_id']],
                    StockMovementType::Issue,
                    '-'.$line['quantity'],
                    -$cost,
                    $data['issued_on'],
                    $data['fiscal_year_id'],
                    $data['academic_year_id'],
                    $actor,
                    [
                        'journal_entry_id' => (int) $entry->getKey(),
                        'reference_type' => 'StockIssue',
                        'reference_id' => (int) $issue->getKey(),
                        'store_requisition_id' => $data['store_requisition_id'] ?? null,
                    ],
                );

                $issue->lines()->create([
                    'item_id' => $line['item_id'],
                    'quantity' => $line['quantity'],
                    'issue_cost' => $cost,
                    'stock_movement_id' => (int) $movement->getKey(),
                ]);
            }

            if (($data['store_requisition_id'] ?? null) !== null) {
                $this->recordRequisitionFulfilment((int) $data['store_requisition_id'], $data['lines']);
            }

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockIssue::class,
                (int) $issue->getKey(),
                null,
                ['issue_no' => $issueNo, 'total_cost' => $total, 'lines' => count($data['lines'])],
                $actor,
            );

            return $issue;
        });
    }

    /**
     * @param  list<array{item_id: int, quantity: string}>  $lines
     */
    private function recordRequisitionFulfilment(int $requisitionId, array $lines): void
    {
        foreach ($lines as $line) {
            /** @var object{id: int|string, quantity_issued: string}|null $row */
            $row = DB::table('store_requisition_lines')
                ->where('store_requisition_id', $requisitionId)
                ->where('item_id', $line['item_id'])
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                continue; // The issue may lawfully exceed the requisition's lines.
            }

            DB::table('store_requisition_lines')->where('id', $row->id)->update([
                'quantity_issued' => WeightedAverageCost::add($row->quantity_issued, $line['quantity']),
                'updated_at' => now(),
            ]);
        }
    }
}
