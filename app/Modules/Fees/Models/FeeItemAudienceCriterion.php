<?php

declare(strict_types=1);

namespace App\Modules\Fees\Models;

use App\Modules\Fees\Domain\AudienceDimension;
use App\Modules\Fees\Domain\CriterionOperator;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * 04-fees.md §2.2.1 - one conjunctive audience row: all of an item's rows
 * must match for the item to apply. Pure config child (CASCADE).
 *
 * @property int $id
 * @property int $fee_item_id
 * @property AudienceDimension $dimension
 * @property CriterionOperator $operator
 * @property list<int|string> $values_json
 */
final class FeeItemAudienceCriterion extends Model
{
    /** @var list<string> */
    protected $fillable = ['fee_item_id', 'dimension', 'operator', 'values_json'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'fee_item_id' => 'integer',
            'dimension' => AudienceDimension::class,
            'operator' => CriterionOperator::class,
            'values_json' => 'array',
        ];
    }

    /**
     * @return BelongsTo<FeeItem, $this>
     */
    public function feeItem(): BelongsTo
    {
        return $this->belongsTo(FeeItem::class);
    }
}
