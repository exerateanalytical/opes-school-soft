<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StockTakeStatus;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockTake;
use App\Modules\Inventory\Models\StockTakeLine;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.10/§8.4 - record the physical counts
 * against the FROZEN system position. Each counted line's variance is
 * priced at the frozen derived cost (empty-bin rule; overage at the same
 * derived cost since there is no purchase document to price from; an
 * overage on an empty system position has no cost basis and is valued 0
 * pending its reason code). Flips counting -> counted and records who
 * counted - the hand the approval must differ from.
 */
final class RecordStockTakeCounts
{
    public function __construct(private readonly WriteAuditEntry $audit) {}

    /**
     * @param  array<int, array{counted_quantity: string, reason_code?: string|null, note?: string|null}>  $counts  keyed by item_id
     */
    public function handle(int $stockTakeId, array $counts, Actor $actor): StockTake
    {
        Gate::authorize(InventoryPermission::POST);

        return DB::transaction(function () use ($stockTakeId, $counts, $actor): StockTake {
            /** @var StockTake|null $take */
            $take = StockTake::query()->lockForUpdate()->find($stockTakeId);

            if ($take === null) {
                throw new DomainException("Stock take {$stockTakeId} does not exist.");
            }

            if ($take->status !== StockTakeStatus::Counting) {
                throw new DomainException(
                    "Stock take '{$take->reference}' is {$take->status->value}; counts are recorded while counting."
                );
            }

            /** @var array<int, StockTakeLine> $lines */
            $lines = $take->lines()->get()->keyBy('item_id')->all();

            foreach ($counts as $itemId => $count) {
                $line = $lines[$itemId] ?? null;

                if ($line === null) {
                    // Found stock of an item with NO frozen balance row: a
                    // fresh line with a zero system position.
                    $line = $take->lines()->create([
                        'item_id' => $itemId,
                        'system_quantity' => '0.000',
                        'system_value' => 0,
                    ]);
                    /** @var StockTakeLine $line */
                    $lines[$itemId] = $line;
                }

                if (WeightedAverageCost::compare($count['counted_quantity'], '0') < 0) {
                    throw new DomainException('A counted quantity cannot be negative.');
                }

                $variance = WeightedAverageCost::subtract($count['counted_quantity'], $line->system_quantity);

                $line->forceFill([
                    'counted_quantity' => $count['counted_quantity'],
                    'variance_value' => WeightedAverageCost::varianceValue(
                        $variance,
                        $line->system_quantity,
                        $line->system_value,
                    ),
                    'reason_code' => $count['reason_code'] ?? null,
                    'note' => $count['note'] ?? null,
                ])->save();
            }

            $uncounted = $take->lines()->whereNull('counted_quantity')->count();

            if ($uncounted > 0) {
                throw new DomainException(
                    "Stock take '{$take->reference}' still has {$uncounted} uncounted line(s); every frozen line needs a physical count."
                );
            }

            $take->forceFill([
                'status' => StockTakeStatus::Counted,
                'counted_by' => $actor->id,
            ])->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                StockTake::class,
                (int) $take->getKey(),
                ['status' => 'counting'],
                ['status' => 'counted', 'counted_by' => $actor->id],
                $actor,
            );

            return $take;
        });
    }
}
