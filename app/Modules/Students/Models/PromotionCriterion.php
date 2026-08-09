<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\Comparator;
use App\Modules\Students\Domain\CriterionType;
use Database\Factories\PromotionCriterionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One rule of a PromotionCriteriaSet (docs/specs/07-students.md §10.4).
 * Each yields pass / fail / indeterminate at evaluation; `is_blocking`
 * decides what a fail costs.
 *
 * @property int $id
 * @property int $criteria_set_id
 * @property CriterionType $type
 * @property Comparator $comparator
 * @property numeric-string $threshold
 * @property int|null $subject_id
 * @property numeric-string $weight
 * @property bool $is_blocking
 * @property int $sequence
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PromotionCriterion extends Model
{
    /** @use HasFactory<PromotionCriterionFactory> */
    use HasFactory;

    protected $table = 'promotion_criteria';

    /** @var list<string> */
    protected $fillable = [
        'criteria_set_id',
        'type',
        'comparator',
        'threshold',
        'subject_id',
        'weight',
        'is_blocking',
        'sequence',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'criteria_set_id' => 'integer',
            'type' => CriterionType::class,
            'comparator' => Comparator::class,
            'threshold' => 'decimal:3',
            'subject_id' => 'integer',
            'weight' => 'decimal:2',
            'is_blocking' => 'boolean',
            'sequence' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PromotionCriteriaSet, $this>
     */
    public function criteriaSet(): BelongsTo
    {
        return $this->belongsTo(PromotionCriteriaSet::class, 'criteria_set_id');
    }

    protected static function newFactory(): PromotionCriterionFactory
    {
        return PromotionCriterionFactory::new();
    }
}
