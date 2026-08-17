<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions;

use App\Modules\Identity\Actions\WriteAuditEntry;
use App\Modules\Identity\Domain\AuditAction;
use App\Modules\Inventory\Actions\Concerns\ValidatesItemCatalogue;
use App\Modules\Inventory\Domain\InventoryPermission;
use App\Modules\Inventory\Models\Item;
use App\Support\Audit\Actor;
use App\Support\Storage\StoredImage;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * The catalogue edit door - CreateItem's invariants, applied to a row that
 * already exists (uniqueness excludes the row itself).
 *
 * Like CreateItem this writes catalogue fields only: no balance, no
 * movement, and never `weighted_avg_cost` (§7.1).
 *
 * The image is the one field with a side effect off the row. The old file is
 * deleted only AFTER the database write succeeds - deleting first and then
 * failing the write would lose the image and keep the path pointing at it -
 * and only when no other item still references that path, so two items
 * uploaded from identical bytes (content hashing gives them the same path)
 * cannot have one's edit blank the other's picture.
 */
final class UpdateItem
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
    public function handle(int $itemId, array $data, Actor $actor): Item
    {
        Gate::authorize(InventoryPermission::MANAGE);

        $attributes = $this->validatedAttributes($data, $itemId);

        $previousImage = null;

        $item = DB::transaction(function () use ($itemId, $attributes, $actor, &$previousImage): Item {
            /** @var Item $item */
            $item = Item::query()->lockForUpdate()->findOrFail($itemId);

            $before = $item->only(array_keys($attributes));
            $previousImage = $item->image_path;

            $item->fill($attributes);
            $item->save();

            $this->audit->handle(
                AuditAction::Updated,
                'inventory',
                Item::class,
                (int) $item->getKey(),
                $before,
                $attributes,
                $actor,
            );

            return $item;
        });

        $keptImage = $attributes['image_path'];

        $this->forgetReplacedImage($itemId, $previousImage, is_string($keptImage) ? $keptImage : null);

        return $item;
    }

    private function forgetReplacedImage(int $itemId, ?string $previous, ?string $keep): void
    {
        if ($previous === null || $previous === '' || $previous === $keep) {
            return;
        }

        $stillReferenced = DB::table('items')
            ->where('image_path', $previous)
            ->where('id', '!=', $itemId)
            ->exists();

        if ($stillReferenced) {
            return;
        }

        StoredImage::forget($previous, $keep);
    }
}
