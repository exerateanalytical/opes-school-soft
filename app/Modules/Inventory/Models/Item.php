<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\ItemStatus;
use App\Modules\Inventory\Domain\ItemType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.3.
 *
 * `weighted_avg_cost` is a DISPLAY-ONLY derived mirror (§7.1): it is never
 * an input to any posting, and an architecture assertion greps that no
 * Action reads it. Quantities are DECIMAL(14,3) and travel as strings.
 *
 * @property int $id
 * @property string $item_code
 * @property string|null $barcode
 * @property string $name
 * @property string|null $description
 * @property int $item_category_id
 * @property ItemType $item_type
 * @property int $unit_of_measure_id
 * @property bool $is_stock_tracked
 * @property string $reorder_level
 * @property string $reorder_quantity
 * @property int|null $standard_sale_price
 * @property int|null $sale_tax_code_id
 * @property int|null $asset_category_id
 * @property ItemStatus $status
 * @property int|null $weighted_avg_cost
 * @property string|null $image_path
 * @property string|null $notes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Item extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'item_code', 'barcode', 'name', 'description',
        'item_category_id', 'item_type', 'unit_of_measure_id',
        'is_stock_tracked', 'reorder_level', 'reorder_quantity',
        'standard_sale_price', 'sale_tax_code_id', 'asset_category_id',
        'status', 'image_path', 'notes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'item_type' => ItemType::class,
            'status' => ItemStatus::class,
            'is_stock_tracked' => 'boolean',
            'reorder_level' => 'string',
            'reorder_quantity' => 'string',
            'standard_sale_price' => 'integer',
            'weighted_avg_cost' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function category(): BelongsTo
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }

    /**
     * @return BelongsTo<UnitOfMeasure, $this>
     */
    public function unit(): BelongsTo
    {
        return $this->belongsTo(UnitOfMeasure::class, 'unit_of_measure_id');
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
