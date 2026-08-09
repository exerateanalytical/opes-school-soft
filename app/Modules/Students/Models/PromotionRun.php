<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\PromotionRunStatus;
use Database\Factories\PromotionRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * docs/specs/07-students.md §10.2 — the persisted evaluation the principal
 * reviews at T0 and applies at T5. `inputs_hash` is the bridge between the
 * two moments; UNIQUE(class_group_id, academic_year_id) is what prevents the
 * second run.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int $class_group_id
 * @property int $target_academic_year_id
 * @property int $criteria_set_id
 * @property PromotionRunStatus $status
 * @property string|null $inputs_hash
 * @property Carbon|null $evaluated_at
 * @property int|null $evaluated_by
 * @property Carbon|null $applied_at
 * @property int|null $applied_by
 * @property string $on_indeterminate
 * @property string $idempotency_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PromotionRun extends Model
{
    /** @use HasFactory<PromotionRunFactory> */
    use HasFactory;

    public const ON_INDETERMINATE_BLOCK = 'block';

    public const ON_INDETERMINATE_MANUAL_REVIEW = 'manual_review';

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'class_group_id',
        'target_academic_year_id',
        'criteria_set_id',
        'status',
        'inputs_hash',
        'evaluated_at',
        'evaluated_by',
        'applied_at',
        'applied_by',
        'on_indeterminate',
        'idempotency_key',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'class_group_id' => 'integer',
            'target_academic_year_id' => 'integer',
            'criteria_set_id' => 'integer',
            'status' => PromotionRunStatus::class,
            'evaluated_at' => 'datetime',
            'evaluated_by' => 'integer',
            'applied_at' => 'datetime',
            'applied_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<PromotionCriteriaSet, $this>
     */
    public function criteriaSet(): BelongsTo
    {
        return $this->belongsTo(PromotionCriteriaSet::class, 'criteria_set_id');
    }

    /**
     * @return HasMany<PromotionDecision, $this>
     */
    public function decisions(): HasMany
    {
        return $this->hasMany(PromotionDecision::class, 'promotion_run_id');
    }

    protected static function newFactory(): PromotionRunFactory
    {
        return PromotionRunFactory::new();
    }
}
