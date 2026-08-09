<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\FeeCategoryFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * 04-fees.md §2.1. Persistence only; archiving and deletion guards live in
 * the Actions.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int $display_order
 * @property bool $is_archived
 */
final class FeeCategory extends Model
{
    /** @use HasFactory<FeeCategoryFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'code', 'name', 'name_fr', 'display_order', 'is_archived',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'display_order' => 'integer',
            'is_archived' => 'boolean',
        ];
    }

    protected static function newFactory(): FeeCategoryFactory
    {
        return FeeCategoryFactory::new();
    }

    /**
     * @return HasMany<FeeItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(FeeItem::class);
    }
}
