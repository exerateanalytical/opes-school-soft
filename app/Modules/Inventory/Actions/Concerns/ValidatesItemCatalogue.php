<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Actions\Concerns;

use App\Modules\Inventory\Domain\ItemStatus;
use App\Modules\Inventory\Domain\ItemType;
use DomainException;
use Illuminate\Support\Facades\DB;

/**
 * The §7.3 catalogue invariants, shared by CreateItem and UpdateItem so the
 * two doors cannot disagree about what a valid item is.
 *
 * Every check throws DomainException rather than returning a bag: the
 * Livewire screen turns that into a field error, and a caller that skips the
 * screen still cannot write a malformed row.
 */
trait ValidatesItemCatalogue
{
    /** A DECIMAL(14,3) quantity as it travels: a plain non-negative decimal string. */
    private const QUANTITY_PATTERN = '/^\d{1,11}(\.\d{1,3})?$/';

    /**
     * @param  array<string, mixed>  $data
     * @param  int|null  $ignoreItemId  the row being updated, excluded from the uniqueness checks
     * @return array<string, mixed>
     */
    private function validatedAttributes(array $data, ?int $ignoreItemId): array
    {
        $itemCode = trim((string) ($data['item_code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($itemCode === '') {
            throw new DomainException('An item needs a code.');
        }

        if (mb_strlen($itemCode) > 30) {
            throw new DomainException('An item code is at most 30 characters.');
        }

        if ($name === '') {
            throw new DomainException('An item needs a name.');
        }

        if (mb_strlen($name) > 160) {
            throw new DomainException('An item name is at most 160 characters.');
        }

        $this->assertUnique('item_code', $itemCode, $ignoreItemId, "Item code '{$itemCode}' is already in use.");

        $barcode = $this->nullableString($data['barcode'] ?? null);

        if ($barcode !== null) {
            $this->assertUnique('barcode', $barcode, $ignoreItemId, "Barcode '{$barcode}' is already in use.");
        }

        $categoryId = (int) ($data['item_category_id'] ?? 0);

        if (! DB::table('item_categories')->where('id', $categoryId)->exists()) {
            throw new DomainException("Item category #{$categoryId} does not exist.");
        }

        $unitId = (int) ($data['unit_of_measure_id'] ?? 0);

        if (! DB::table('units_of_measure')->where('id', $unitId)->exists()) {
            throw new DomainException("Unit of measure #{$unitId} does not exist.");
        }

        $itemType = ItemType::tryFrom((string) ($data['item_type'] ?? ''));

        if ($itemType === null) {
            throw new DomainException('An item is one of: consumable, equipment, merchandise.');
        }

        $status = ItemStatus::tryFrom((string) ($data['status'] ?? ItemStatus::Active->value));

        if ($status === null) {
            throw new DomainException('An item status is one of: active, discontinued, archived.');
        }

        $salePrice = $data['standard_sale_price'] ?? null;

        if ($salePrice !== null) {
            $salePrice = (int) $salePrice;

            if ($salePrice < 0) {
                throw new DomainException('A standard sale price cannot be negative.');
            }
        }

        $taxCodeId = $this->nullableId($data['sale_tax_code_id'] ?? null);

        if ($taxCodeId !== null && ! DB::table('tax_codes')->where('id', $taxCodeId)->exists()) {
            throw new DomainException("Tax code #{$taxCodeId} does not exist.");
        }

        $assetCategoryId = $this->nullableId($data['asset_category_id'] ?? null);

        if ($assetCategoryId !== null) {
            if ($itemType !== ItemType::Equipment) {
                // I4: an asset category turns a receipt into the §8.6
                // capitalisation handoff, which only equipment may take.
                throw new DomainException('Only an equipment item may name an asset category (I4).');
            }

            if (! DB::table('asset_categories')->where('id', $assetCategoryId)->exists()) {
                throw new DomainException("Asset category #{$assetCategoryId} does not exist.");
            }
        }

        return [
            'item_code' => $itemCode,
            'barcode' => $barcode,
            'name' => $name,
            'description' => $this->nullableString($data['description'] ?? null),
            'item_category_id' => $categoryId,
            'item_type' => $itemType->value,
            'unit_of_measure_id' => $unitId,
            'is_stock_tracked' => (bool) ($data['is_stock_tracked'] ?? true),
            'reorder_level' => $this->quantity($data['reorder_level'] ?? null, 'reorder level'),
            'reorder_quantity' => $this->quantity($data['reorder_quantity'] ?? null, 'reorder quantity'),
            'standard_sale_price' => $salePrice,
            'sale_tax_code_id' => $taxCodeId,
            'asset_category_id' => $assetCategoryId,
            'status' => $status->value,
            'image_path' => $this->nullableString($data['image_path'] ?? null),
            'notes' => $this->nullableString($data['notes'] ?? null),
        ];
    }

    private function assertUnique(string $column, string $value, ?int $ignoreItemId, string $message): void
    {
        $clash = DB::table('items')
            ->where($column, $value)
            ->when($ignoreItemId !== null, fn ($q) => $q->where('id', '!=', $ignoreItemId))
            ->exists();

        if ($clash) {
            throw new DomainException($message);
        }
    }

    private function quantity(mixed $value, string $label): string
    {
        $quantity = trim((string) ($value ?? ''));

        if ($quantity === '') {
            return '0.000';
        }

        if (preg_match(self::QUANTITY_PATTERN, $quantity) !== 1) {
            throw new DomainException("A {$label} is a non-negative quantity.");
        }

        return $quantity;
    }

    private function nullableString(mixed $value): ?string
    {
        $string = trim((string) ($value ?? ''));

        return $string === '' ? null : $string;
    }

    private function nullableId(mixed $value): ?int
    {
        if ($value === null || $value === '' || (int) $value === 0) {
            return null;
        }

        return (int) $value;
    }
}
