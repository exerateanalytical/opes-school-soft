<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Concerns;

use App\Modules\Inventory\Domain\ItemStatus;
use App\Modules\Inventory\Domain\StockMovementType;
use App\Modules\Inventory\Domain\WeightedAverageCost;
use App\Modules\Inventory\Models\StockMovement;
use App\Support\Audit\Actor;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The ONE writer of `stock_balances` + `stock_movements`, shared by every
 * stock Action (docs/specs/06-assets-stores.md §7.5/§7.6):
 *
 *  - every movement takes SELECT..FOR UPDATE on the balance row(s), with
 *    multi-row locks ordered by (item_id, store_location_id) ASCENDING -
 *    the mandatory anti-deadlock rule, exposed pure as balanceLockOrder()
 *    so StockConcurrencyTest can assert it directly;
 *  - the §7.10 counting freeze is checked under the LOCATION row lock,
 *    taken before any balance lock;
 *  - invariants I6-I10 are asserted in code (readable refusals) with the
 *    table CHECKs as the last line of defence;
 *  - movements are inserted AFTER their journal entry exists, because I11's
 *    append-only triggers leave no way to stamp `journal_entry_id` later.
 */
trait MovesStock
{
    /**
     * The mandatory §7.5 lock order for a set of (item_id, location_id)
     * pairs: ascending by item, then location. Pure - Pest asserts both
     * directions of a transfer produce the identical sequence.
     *
     * @param  list<array{0: int, 1: int}>  $pairs
     * @return list<array{0: int, 1: int}>
     */
    public static function balanceLockOrder(array $pairs): array
    {
        $unique = [];

        foreach ($pairs as $pair) {
            $unique[$pair[0].':'.$pair[1]] = $pair;
        }

        $ordered = array_values($unique);

        usort($ordered, static fn (array $a, array $b): int => $a[0] === $b[0]
            ? $a[1] <=> $b[1]
            : $a[0] <=> $b[0]);

        return $ordered;
    }

    /**
     * Lock the location row and enforce active + §7.10 counting-freeze.
     * Taken BEFORE any balance lock, so movements serialise against
     * StartStockTake's flag flip.
     *
     * @return object{id: int, code: string, is_active: bool, is_sellable_point: bool, counting_stock_take_id: int|null}
     */
    private function lockLocation(int $locationId, ?int $allowCountingTakeId = null): object
    {
        /** @var object{id: int|string, code: string, is_active: int|bool, is_sellable_point: int|bool, counting_stock_take_id: int|string|null}|null $row */
        $row = DB::table('store_locations')->where('id', $locationId)->lockForUpdate()->first();

        if ($row === null) {
            throw new DomainException("Store location {$locationId} does not exist.");
        }

        if (! (bool) $row->is_active) {
            throw new DomainException("Store location '{$row->code}' is inactive; no stock may move there.");
        }

        $countingTakeId = $row->counting_stock_take_id === null ? null : (int) $row->counting_stock_take_id;

        if ($countingTakeId !== null && $countingTakeId !== $allowCountingTakeId) {
            throw new DomainException(
                "Store location '{$row->code}' is frozen for stock take #{$countingTakeId} (06-assets-stores §7.10); movements are blocked until the count is posted or cancelled."
            );
        }

        return (object) [
            'id' => (int) $row->id,
            'code' => $row->code,
            'is_active' => (bool) $row->is_active,
            'is_sellable_point' => (bool) $row->is_sellable_point,
            'counting_stock_take_id' => $countingTakeId,
        ];
    }

    /**
     * Insert-if-absent then SELECT..FOR UPDATE on the (item, location)
     * balance row - the §7.5 locked row. Returned as a mutable stdClass:
     * applyMovement() advances it in place so chained lines see the
     * post-movement position.
     *
     * @return \stdClass&object{item_id: int, store_location_id: int, quantity_on_hand: string, quantity_reserved: string, value_on_hand: int}
     */
    private function lockBalance(int $itemId, int $locationId): object
    {
        DB::statement(
            'INSERT IGNORE INTO stock_balances (item_id, store_location_id, quantity_on_hand, quantity_reserved, value_on_hand, created_at, updated_at)
             VALUES (?, ?, 0, 0, 0, NOW(), NOW())',
            [$itemId, $locationId],
        );

        /** @var object{item_id: int|string, store_location_id: int|string, quantity_on_hand: string, quantity_reserved: string, value_on_hand: int|string}|null $row */
        $row = DB::table('stock_balances')
            ->where('item_id', $itemId)
            ->where('store_location_id', $locationId)
            ->lockForUpdate()
            ->first();

        if ($row === null) {
            throw new DomainException("Stock balance for item {$itemId} at location {$locationId} could not be locked.");
        }

        return (object) [
            'item_id' => (int) $row->item_id,
            'store_location_id' => (int) $row->store_location_id,
            'quantity_on_hand' => $row->quantity_on_hand,
            'quantity_reserved' => $row->quantity_reserved,
            'value_on_hand' => (int) $row->value_on_hand,
        ];
    }

    /**
     * The item + its category accounts, with invariant I2 enforced for the
     * account roles the caller needs: refuse BY NAME when unconfigured -
     * never default (00-core §16).
     *
     * @param  list<string>  $requiredAccounts  subset of purchase|stock|variation|sales
     * @return object{id: int, item_code: string, name: string, item_type: string, status: string, is_stock_tracked: bool, asset_category_id: int|null, standard_sale_price: int|null, sale_tax_code_id: int|null, category_id: int, category_code: string, purchase_account_id: int|null, stock_account_id: int|null, variation_account_id: int|null, sales_account_id: int|null}
     */
    private function itemWithAccounts(int $itemId, array $requiredAccounts = ['purchase', 'stock', 'variation']): object
    {
        /** @var object{id: int|string, item_code: string, name: string, item_type: string, status: string, is_stock_tracked: int|bool, asset_category_id: int|string|null, standard_sale_price: int|string|null, sale_tax_code_id: int|string|null, category_id: int|string, category_code: string, purchase_account_id: int|string|null, stock_account_id: int|string|null, variation_account_id: int|string|null, sales_account_id: int|string|null}|null $row */
        $row = DB::table('items')
            ->join('item_categories', 'item_categories.id', '=', 'items.item_category_id')
            ->where('items.id', $itemId)
            ->select([
                'items.id', 'items.item_code', 'items.name', 'items.item_type',
                'items.status', 'items.is_stock_tracked', 'items.asset_category_id',
                'items.standard_sale_price', 'items.sale_tax_code_id',
                'item_categories.id as category_id',
                'item_categories.code as category_code',
                'item_categories.purchase_account_id',
                'item_categories.stock_account_id',
                'item_categories.variation_account_id',
                'item_categories.sales_account_id',
            ])
            ->first();

        if ($row === null) {
            throw new DomainException("Item {$itemId} does not exist.");
        }

        $item = (object) [
            'id' => (int) $row->id,
            'item_code' => $row->item_code,
            'name' => $row->name,
            'item_type' => $row->item_type,
            'status' => $row->status,
            'is_stock_tracked' => (bool) $row->is_stock_tracked,
            'asset_category_id' => $row->asset_category_id === null ? null : (int) $row->asset_category_id,
            'standard_sale_price' => $row->standard_sale_price === null ? null : (int) $row->standard_sale_price,
            'sale_tax_code_id' => $row->sale_tax_code_id === null ? null : (int) $row->sale_tax_code_id,
            'category_id' => (int) $row->category_id,
            'category_code' => $row->category_code,
            'purchase_account_id' => $row->purchase_account_id === null ? null : (int) $row->purchase_account_id,
            'stock_account_id' => $row->stock_account_id === null ? null : (int) $row->stock_account_id,
            'variation_account_id' => $row->variation_account_id === null ? null : (int) $row->variation_account_id,
            'sales_account_id' => $row->sales_account_id === null ? null : (int) $row->sales_account_id,
        ];

        if (! $item->is_stock_tracked) {
            throw new DomainException("Item '{$item->item_code}' is not stock-tracked; it has no bin to move.");
        }

        if ($item->status === ItemStatus::Archived->value) {
            throw new DomainException("Item '{$item->item_code}' is archived (I5); nothing may move.");
        }

        foreach ($requiredAccounts as $role) {
            $column = $role.'_account_id';

            if ($item->{$column} === null) {
                throw new DomainException(
                    "Item category '{$item->category_code}' has no configured {$role} account; the accountant must configure it before item '{$item->item_code}' can move (invariant I2, 00-core §16)."
                );
            }
        }

        return $item;
    }

    /**
     * Apply a signed delta to a LOCKED balance row and insert the
     * append-only movement. The caller has already posted (or lawfully
     * skipped) the ledger leg - `journal_entry_id` rides in $attributes.
     *
     * @param  \stdClass&object{item_id: int, store_location_id: int, quantity_on_hand: string, quantity_reserved: string, value_on_hand: int}  $balance
     * @param  array<string, mixed>  $attributes
     */
    private function applyMovement(
        object $balance,
        StockMovementType $type,
        string $signedQuantity,
        int $signedCost,
        string $movedOn,
        int $fiscalYearId,
        int $academicYearId,
        Actor $actor,
        array $attributes = [],
    ): StockMovement {
        // I10: quantity and money never move in opposite directions.
        $quantitySign = WeightedAverageCost::compare($signedQuantity, '0');

        if (($quantitySign > 0 && $signedCost < 0) || ($quantitySign < 0 && $signedCost > 0)) {
            throw new DomainException('A stock movement cannot take quantity one way and value the other (I10).');
        }

        $newQuantity = WeightedAverageCost::add($balance->quantity_on_hand, $signedQuantity);
        $newValue = $balance->value_on_hand + $signedCost;

        if (WeightedAverageCost::compare($newQuantity, '0') < 0) {
            throw new DomainException(sprintf(
                'Insufficient stock: %s on hand, movement of %s refused (I6: negative stock is rejected, not permitted-and-warned).',
                $balance->quantity_on_hand,
                $signedQuantity,
            ));
        }

        if ($newValue < 0) {
            throw new DomainException('A stock movement may not drive value_on_hand negative (I9).');
        }

        if (WeightedAverageCost::isZero($newQuantity) !== ($newValue === 0)) {
            throw new DomainException(sprintf(
                'Movement would leave quantity %s against value %d, violating I8 (empty bin = zero value); the §7.1 empty-bin costing rule was not applied.',
                $newQuantity,
                $newValue,
            ));
        }

        if (WeightedAverageCost::compare($newQuantity, $balance->quantity_reserved) < 0) {
            throw new DomainException(sprintf(
                'Movement would leave %s on hand below the %s reserved (I7); release reservations first.',
                $newQuantity,
                $balance->quantity_reserved,
            ));
        }

        DB::table('stock_balances')
            ->where('item_id', $balance->item_id)
            ->where('store_location_id', $balance->store_location_id)
            ->update([
                'quantity_on_hand' => $newQuantity,
                'value_on_hand' => $newValue,
                'last_movement_at' => now(),
                'updated_at' => now(),
            ]);

        // Keep the display-only mirror honest (§7.1: derived, never an
        // input; recomputed from the authoritative totals across bins).
        $this->refreshDisplayAverage($balance->item_id);

        $movement = StockMovement::query()->create($attributes + [
            'movement_type' => $type,
            'item_id' => $balance->item_id,
            'store_location_id' => $balance->store_location_id,
            'quantity' => $signedQuantity,
            'unit_cost' => WeightedAverageCost::descriptiveUnitCost($signedQuantity, $signedCost),
            'total_cost' => $signedCost,
            'balance_qty_after' => $newQuantity,
            'balance_value_after' => $newValue,
            'moved_on' => $movedOn,
            'fiscal_year_id' => $fiscalYearId,
            'academic_year_id' => $academicYearId,
            'performed_by' => $actor->id,
            'created_at' => now(),
        ]);

        // Hand the caller the post-movement position for chained lines.
        $balance->quantity_on_hand = $newQuantity;
        $balance->value_on_hand = $newValue;

        return $movement;
    }

    /**
     * items.weighted_avg_cost is a DISPLAY column: round(sum value / sum
     * qty) across locations, null when empty. Never read by any Action -
     * the F3 suite greps for that.
     */
    private function refreshDisplayAverage(int $itemId): void
    {
        /** @var object{total_qty: string|null, total_value: string|int|null}|null $totals */
        $totals = DB::table('stock_balances')
            ->where('item_id', $itemId)
            ->selectRaw('SUM(quantity_on_hand) as total_qty, SUM(value_on_hand) as total_value')
            ->first();

        $display = null;

        if ($totals !== null && $totals->total_qty !== null && ! WeightedAverageCost::isZero($totals->total_qty)) {
            $display = WeightedAverageCost::descriptiveUnitCost($totals->total_qty, (int) $totals->total_value);
        }

        DB::table('items')->where('id', $itemId)->update(['weighted_avg_cost' => $display]);
    }
}
