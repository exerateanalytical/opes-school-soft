<?php

declare(strict_types=1);

namespace App\Modules\Inventory\Models;

use App\Modules\Inventory\Domain\StoreLocationType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/06-assets-stores.md §7.4. `counting_stock_take_id` is the
 * §7.10 freeze flag: while set, every movement at this location is blocked
 * (checked under the location row lock).
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property StoreLocationType $type
 * @property int|null $school_section_id
 * @property int|null $keeper_staff_id
 * @property bool $is_sellable_point
 * @property bool $is_active
 * @property int|null $counting_stock_take_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class StoreLocation extends Model
{
    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'type', 'school_section_id', 'keeper_staff_id',
        'is_sellable_point', 'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => StoreLocationType::class,
            'is_sellable_point' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    /**
     * @return HasMany<StockBalance, $this>
     */
    public function balances(): HasMany
    {
        return $this->hasMany(StockBalance::class);
    }
}
