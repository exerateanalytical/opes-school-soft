<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.2. The three C6 accounts (four for
 * merchandise) ship NULL - invariant I2 blocks movement until the
 * accountant configures them; nothing is ever seeded (00-core §16).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string|null $name_fr
 * @property int|null $parent_id
 * @property int|null $purchase_account_id
 * @property int|null $stock_account_id
 * @property int|null $variation_account_id
 * @property int|null $sales_account_id
 * @property bool $cost_of_sales_uses_variation
 * @property int|null $default_tax_code_id
 * @property bool $is_archived
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ItemCategory extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'parent_id',
        'purchase_account_id', 'stock_account_id', 'variation_account_id',
        'sales_account_id', 'cost_of_sales_uses_variation',
        'default_tax_code_id', 'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'cost_of_sales_uses_variation' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<ItemCategory, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<Item, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(Item::class);
    }
}
