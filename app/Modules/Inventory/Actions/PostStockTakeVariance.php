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
use App\Modules\Inventory\Domain\StockTakeStatus;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\StockTakeLine;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §8.4 - land the approved count. Shortage:
 * Dr 603x / Cr 3x; overage the mirror, both via the ONE posting path
 * (`inventory.stocktake.variance`, amount signed so the school's rules
 * discriminate direction by condition). Movements are adjustment_in/out at
 * the approved variance values, the location's freeze flag clears, and the
 * take closes `posted`.
 *
 * V16: routing shortages to a 658-family loss account instead of 603x is
 * NEEDS VERIFICATION; `stock_take_lines.loss_account_id` exists but is
 * unavailable until 02-accounting resolves the account.
 */
final class PostStockTakeVariance
{
    use MovesStock;

    public function __construct(
        private readonly PostFromEvent $post,
        private readonly WriteAuditEntry $audit,
    ) {}

    public function handle(int $stockTakeId, Actor $actor): StockTake
    {
        Gate::authorize(InventoryPermission::POST);

        return DB::transaction(function () use ($stockTakeId, $actor): StockTake {
            /** @var StockTake|null $take */
            $take = StockTake::query()->lockForUpdate()->find($stockTakeId);

            if ($take === null) {
                throw new DomainException("Stock take {$stockTakeId} does not exist.");
            }

            if ($take->status !== StockTakeStatus::Approved) {
                throw new DomainException(
                    "Stock take '{$take->reference}' is {$take->status->value}; only an approved take can post."
                );
            }

            $location = $this->lockLocation($take->store_location_id, (int) $take->getKey());

            if ($location->counting_stock_take_id !== (int) $take->getKey()) {
                throw new DomainException(
                    "Stock take '{$take->reference}' no longer holds the counting freeze at its location; it cannot post."
                );
            }

            /** @var list<StockTakeLine> $variantLines */
            $variantLines = $take->lines()
                ->whereNotNull('counted_quantity')
                ->get()
                ->filter(static fn (StockTakeLine $line): bool => $line->variance_quantity !== null
                    && WeightedAverageCost::compare($line->variance_quantity, '0') !== 0)
                ->values()
                ->all();

            // Net signed variance value per (stock, variation) account pair
            // - one JE per pair through the one posting path.
            $groups = [];
            $lineAccounts = [];

            foreach ($variantLines as $line) {
                $item = $this->itemWithAccounts($line->item_id, ['stock', 'variation']);

                $variance = (string) $line->variance_quantity;

                if (WeightedAverageCost::compare($variance, '0') > 0 && (int) $line->variance_value === 0 && $line->system_value === 0) {
                    throw new DomainException(
                        "Stock take '{$take->reference}': item '{$item->item_code}' shows an overage on a zero-value position - there is no cost basis to price it (I8). Receive the found stock with its real cost instead."
                    );
                }

                $key = $item->stock_account_id.':'.$item->variation_account_id;
                $groups[$key] ??= ['stock' => (int) $item->stock_account_id, 'variation' => (int) $item->variation_account_id, 'net' => 0];
                $groups[$key]['net'] += (int) $line->variance_value;
                $lineAccounts[(int) $line->getKey()] = $key;
            }

            // Ledger legs first (movements are append-only; the entry id
            // rides on the insert).
            $entryByGroup = [];
            $firstEntryId = null;

            foreach ($groups as $key => $group) {
                if ($group['net'] === 0) {
                    $entryByGroup[$key] = null;

                    continue;
                }

                $entry = $this->post->handle(
                    PostingEvent::InventoryStocktakeVariance->value,
                    [
                        'movement' => [
                            'amount' => $group['net'],
                            'reference' => $take->reference,
                            'stock_account_id' => $group['stock'],
                            'variation_account_id' => $group['variation'],
                        ],
                    ],
                    $take->count_date->toDateString(),
                    $actor,
                    $take->reference,
                );

                $entryByGroup[$key] = (int) $entry->getKey();
                $firstEntryId ??= (int) $entry->getKey();
            }

            // Movements per variant line, in the mandatory balance order.
            $pairs = [];

            foreach ($variantLines as $line) {
                $pairs[] = [$line->item_id, $take->store_location_id];
            }

            $balances = [];

            foreach (self::balanceLockOrder($pairs) as [$itemId, $locationId]) {
                $balances[$itemId] = $this->lockBalance($itemId, $locationId);
            }

            foreach ($variantLines as $line) {
                $variance = (string) $line->variance_quantity;
                $positive = WeightedAverageCost::compare($variance, '0') > 0;
                $entryId = $entryByGroup[$lineAccounts[(int) $line->getKey()]];

                $this->applyMovement(
                    $balances[$line->item_id],
                    $positive ? StockMovementType::AdjustmentIn : StockMovementType::AdjustmentOut,
                    $variance,
                    (int) $line->variance_value,
                    $take->count_date->toDateString(),
                    $take->fiscal_year_id,
                    $take->academic_year_id,
                    $actor,
                    [
                        'journal_entry_id' => $entryId,
                        'posting_deferred_reason' => $entryId === null ? 'variance_nets_to_zero_within_account_pair' : null,
                        'reference_type' => 'StockTakeLine',
                        'reference_id' => (int) $line->getKey(),
                    ],
                );
            }

            DB::table('store_locations')->where('id', $take->store_location_id)->update([
                'counting_stock_take_id' => null,
                'updated_at' => now(),
            ]);

            $take->forceFill([
                'status' => StockTakeStatus::Posted,
                'journal_entry_id' => $firstEntryId,
            ])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                StockTake::class,
                (int) $take->getKey(),
                ['status' => 'approved'],
                ['status' => 'posted', 'journal_entry_id' => $firstEntryId, 'variant_lines' => count($variantLines)],
                $actor,
            );

            return $take;
        });
    }
}
