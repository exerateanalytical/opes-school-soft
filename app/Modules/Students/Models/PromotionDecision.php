<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use Database\Factories\PromotionDecisionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * The minimal per-enrollment promotion decision the rollover wizard's step 6
 * consumes (phase-07 plan decision 4; docs/specs/08-operations.md §6.2).
 * One decision per enrollment, by constraint; Phase 8's full promotion
 * engine builds on this table.
 *
 * `decision` stays a plain string with the four values as constants -
 * Phase 8 owns the richer PromotionOutcome vocabulary and must not be
 * pre-empted here.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property string $decision
 * @property string|null $target_class_group_key
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
        'enrollment_id',
        'decision',
        'target_class_group_key',
        'decided_by',
        'decided_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
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
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): PromotionDecisionFactory
    {
        return PromotionDecisionFactory::new();
    }
}
