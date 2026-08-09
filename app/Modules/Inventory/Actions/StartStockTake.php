<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\MovesStock;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Domain\StockTakeStatus;
use App\Modules\Inventory\Models\StockTake;
use App\Support\Audit\Actor;
use App\Support\Sequence\SequenceAllocator;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.10 - FREEZE SEMANTICS. Under the
 * location row lock: snapshot system_quantity/system_value for every item
 * with a balance row at the location, flip the location's
 * `counting_stock_take_id` flag, and open the take at `counting`.
 * Movements at the location are blocked from this instant until the count
 * posts or cancels - the alternative (move-and-reconcile) is where
 * stock-take arithmetic goes wrong in every system that tries it. For a
 * school the blocking window is minutes, not days.
 */
final class StartStockTake
{
    use MovesStock;

    public function __construct(
        private readonly SequenceAllocator $sequence,
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     store_location_id: int,
     *     count_date: string,
     *     fiscal_year_id: int,
     *     academic_year_id: int,
     * } $data
     */
    public function handle(array $data, Actor $actor): StockTake
    {
        Gate::authorize(InventoryPermission::POST);

        return DB::transaction(function () use ($data, $actor): StockTake {
            // lockLocation() itself refuses when a count is already open.
            $location = $this->lockLocation($data['store_location_id']);

            $year = Carbon::parse($data['count_date'])->format('Y');
            $reference = sprintf('ST/%s/%04d', $year, $this->sequence->allocate('stock_take_no.'.$year));

            $take = StockTake::query()->create([
                'reference' => $reference,
                'store_location_id' => $location->id,
                'is_full_count' => false,
                'count_date' => $data['count_date'],
                'status' => StockTakeStatus::Counting,
                'fiscal_year_id' => $data['fiscal_year_id'],
                'academic_year_id' => $data['academic_year_id'],
                'created_by' => $actor->id,
            ]);

            // The frozen system position - every stocked item at the
            // location, including zero rows (they can still be over-counted).
            /** @var list<object{item_id: int|string, quantity_on_hand: string, value_on_hand: int|string}> $rows */
            $rows = DB::table('stock_balances')
                ->where('store_location_id', $location->id)
                ->orderBy('item_id')
                ->lockForUpdate()
                ->get(['item_id', 'quantity_on_hand', 'value_on_hand'])
                ->all();

            foreach ($rows as $row) {
                $take->lines()->create([
                    'item_id' => (int) $row->item_id,
                    'system_quantity' => $row->quantity_on_hand,
                    'system_value' => (int) $row->value_on_hand,
                ]);
            }

            DB::table('store_locations')->where('id', $location->id)->update([
                'counting_stock_take_id' => (int) $take->getKey(),
                'updated_at' => now(),
            ]);

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                StockTake::class,
                (int) $take->getKey(),
                null,
                ['reference' => $reference, 'store_location_id' => $location->id, 'lines' => count($rows)],
                $actor,
            );

            return $take;
        });
    }
}
