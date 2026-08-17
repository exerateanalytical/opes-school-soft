<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\ValidatesItemCatalogue;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Models\Item;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * docs/specs/06-assets-stores.md §7.3 - the catalogue door. Until this
 * existed an item could only reach the database through a seeder, so a
 * school could not add so much as a box of chalk through the UI.
 *
 * Catalogue, not value: creating an item posts NOTHING. It opens no stock
 * balance and writes no movement - the first receipt does both. In
 * particular `weighted_avg_cost` is not an input here (§7.1: it is a
 * display-only mirror the movement path maintains).
 *
 * Gated `inventory.manage`, the catalogue-side ability ReceiveStock already
 * uses, so no new permission string has to be granted anywhere.
 */
final class CreateItem
{
    use ValidatesItemCatalogue;

    public function __construct(
        private readonly WriteAuditEntry $audit,
    ) {}

    /**
     * @param array{
     *     item_code: string,
     *     name: string,
     *     item_category_id: int,
     *     item_type: string,
     *     unit_of_measure_id: int,
     *     barcode?: string|null,
     *     description?: string|null,
     *     is_stock_tracked?: bool,
     *     reorder_level?: string|null,
     *     reorder_quantity?: string|null,
     *     standard_sale_price?: int|null,
     *     sale_tax_code_id?: int|null,
     *     asset_category_id?: int|null,
     *     status?: string,
     *     image_path?: string|null,
     *     notes?: string|null,
     * } $data
     */
    public function handle(array $data, Actor $actor): Item
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $attributes = $this->validatedAttributes($data, null);

        return DB::transaction(function () use ($attributes, $actor): Item {
            /** @var Item $item */
            $item = Item::query()->create($attributes);

            $this->audit->handle(
                AuditAction::Created,
                'inventory',
                Item::class,
                (int) $item->getKey(),
                null,
                $attributes,
                $actor,
            );

            return $item;
        });
    }
}
