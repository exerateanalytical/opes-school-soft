<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use Database\Factories\FeeStructureLineFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * 04-fees.md §2.5. `amount` is whole FCFA (BIGINT, 00-core §7).
 * `term_id` uses the NOT NULL sentinel 0 for "annual" so the
 * UNIQUE(fee_structure_id, fee_item_id, term_id) key cannot be defeated by
 * MySQL's duplicate-NULL behaviour.
 *
 * @property int $id
 * @property int $fee_structure_id
 * @property int $fee_item_id
 * @property int $amount
 * @property int $term_id 0 = annual (sentinel)
 * @property Carbon|null $service_period_start
 * @property Carbon|null $service_period_end
 * @property bool $is_optional
 * @property int $display_order
 */
final class FeeStructureLine extends Model
{
    /** @use HasFactory<FeeStructureLineFactory> */
    use HasFactory;

    public const ANNUAL = 0;

    /** @var list<string> */
    protected $fillable = [
        'fee_structure_id', 'fee_item_id', 'amount', 'term_id',
        'service_period_start', 'service_period_end', 'is_optional', 'display_order',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_structure_id' => 'integer',
            'fee_item_id' => 'integer',
            'amount' => 'integer',
            'term_id' => 'integer',
            'service_period_start' => 'date',
            'service_period_end' => 'date',
            'is_optional' => 'boolean',
            'display_order' => 'integer',
        ];
    }

    protected static function newFactory(): FeeStructureLineFactory
    {
        return FeeStructureLineFactory::new();
    }

    /**
     * @return BelongsTo<FeeStructure, $this>
     */
    public function structure(): BelongsTo
    {
        return $this->belongsTo(FeeStructure::class, 'fee_structure_id');
    }

    /**
     * @return BelongsTo<FeeItem, $this>
     */
    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }
}
