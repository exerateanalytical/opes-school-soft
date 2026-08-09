<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\PromotionOutcome;
use Database\Factories\PromotionDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * THE one promotion-decision record (docs/specs/07-students.md §10.5),
 * converged on by two writers:
 *
 *  - Phase 7's rollover step-6 grid (RecordPromotionDecision) writes the
 *    legacy four-value `decision` with no run;
 *  - Phase 8's promotion engine (EvaluatePromotionRun) writes the full §10.5
 *    row — `outcome`, `computed_outcome`, `criteria_results` — AND the
 *    legacy `decision` via PromotionOutcome::legacyDecision(), so the
 *    rollover wizard keeps reading one answer.
 *
 * `computed_outcome` is retained beside `outcome` so a manual change is never
 * invisible; `criteria_results` stores value/threshold/comparator/verdict per
 * criterion so the printed promotion list explains itself.
 *
 * @property int $id
 * @property int|null $promotion_run_id
 * @property int $enrollment_id
 * @property PromotionOutcome|null $outcome
 * @property string|null $computed_outcome
 * @property string|null $decision
 * @property string|null $target_class_group_key
 * @property int|null $target_class_level_id
 * @property int|null $target_class_group_id
 * @property bool $overridden
 * @property string|null $override_reason
 * @property int|null $overridden_by
 * @property array<string, mixed>|null $criteria_results
 * @property numeric-string|null $annual_average
 * @property numeric-string|null $attendance_rate
 * @property int|null $applied_enrollment_id
 * @property int $decided_by
 * @property Carbon $decided_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PromotionDecision extends Model
{
    /** @use HasFactory<PromotionDecisionFactory> */
    use HasFactory;

    public const DECISION_PROMOTED = 'promoted';

    public const DECISION_REPEAT = 'repeat';

    public const DECISION_GRADUATED = 'graduated';

    public const DECISION_WITHDRAWN = 'withdrawn';

    /** @var list<string> */
    protected $fillable = [
        'promotion_run_id',
        'enrollment_id',
        'outcome',
        'computed_outcome',
        'decision',
        'target_class_group_key',
        'target_class_level_id',
        'target_class_group_id',
        'overridden',
        'override_reason',
        'overridden_by',
        'criteria_results',
        'annual_average',
        'attendance_rate',
        'applied_enrollment_id',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'promotion_run_id' => 'integer',
            'enrollment_id' => 'integer',
            'outcome' => PromotionOutcome::class,
            'target_class_level_id' => 'integer',
            'target_class_group_id' => 'integer',
            'overridden' => 'boolean',
            'overridden_by' => 'integer',
            'criteria_results' => 'array',
            'annual_average' => 'decimal:3',
            'attendance_rate' => 'decimal:3',
            'applied_enrollment_id' => 'integer',
            'decided_by' => 'integer',
            'decided_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    /**
     * @return BelongsTo<PromotionRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(PromotionRun::class, 'promotion_run_id');
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): PromotionDecisionFactory
    {
        return PromotionDecisionFactory::new();
    }
}
