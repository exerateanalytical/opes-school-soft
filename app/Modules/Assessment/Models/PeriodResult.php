<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Database\Factories\PeriodResultFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * The persisted output of pipeline stages 5 and 6 for one (period, enrollment)
 * - docs/specs/01-assessment.md 10.
 *
 * NO relations are declared to AssessmentPeriod, Enrollment or ClassGroup.
 * tests/Architecture/ModuleBoundaryTest.php forbids this module from using
 * another module's Models and states that the rule is absolute ("No
 * exceptions"); Phase 2's Students\Models\Enrollment sets the precedent of
 * exposing the foreign keys as plain integers and reading the other module's
 * rows through the query builder, which involves no cross-module class at all.
 *
 * @property int $id
 * @property int $assessment_period_id
 * @property int $enrollment_id
 * @property int $class_group_id
 * @property int|null $framework_id
 * @property int|null $grade_band_id
 * @property string $cohort_key
 * @property string $rank_scope
 * @property string $coefficient_sum
 * @property string|null $weighted_total
 * @property string|null $general_average
 * @property string|null $general_average_rounded
 * @property bool|null $is_pass
 * @property string|null $gpa
 * @property int $subjects_counted
 * @property array<string, mixed>|null $subject_scores
 * @property int|null $rank_position
 * @property int|null $rank_denominator
 * @property bool $is_ranked
 * @property string|null $nc_reason
 * @property Carbon|null $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class PeriodResult extends Model
{
    /** @use HasFactory<PeriodResultFactory> */
    use HasFactory;

    /**
     * 10.5. A student is NC for exactly three reasons and the card prints a
     * footnote naming which - an unexplained `NC` is what a parent telephones
     * about.
     */
    public const NC_NULL_AVERAGE = 'null_average';

    public const NC_NOT_YET_ASSESSABLE = 'not_yet_assessable';

    public const NC_INSUFFICIENT_PERIODS = 'insufficient_periods';

    /**
     * The literal `all` cohort key. `same_stream` and `identical_basket` keys
     * are fingerprints built by ComputePeriodResults::cohortKeyFor().
     */
    public const COHORT_KEY_ALL = 'all';

    /** @var list<string> */
    protected $fillable = [
        'assessment_period_id',
        'enrollment_id',
        'class_group_id',
        'framework_id',
        'grade_band_id',
        'cohort_key',
        'rank_scope',
        'coefficient_sum',
        'weighted_total',
        'general_average',
        'general_average_rounded',
        'is_pass',
        'gpa',
        'subjects_counted',
        'subject_scores',
        'rank_position',
        'rank_denominator',
        'is_ranked',
        'nc_reason',
        'computed_at',
    ];

    /**
     * The DECIMAL columns are deliberately NOT cast to float or to Laravel's
     * `decimal:n`: 00-core 7.1 keeps scores off float entirely, and they are
     * carried as strings into App\Support\Score\Score, which holds them as
     * integer thousandths. A float cast here would reintroduce exactly the
     * invisible-decimal divergence invariant 9 exists to prevent.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_period_id' => 'integer',
            'enrollment_id' => 'integer',
            'class_group_id' => 'integer',
            'framework_id' => 'integer',
            'grade_band_id' => 'integer',
            'is_pass' => 'boolean',
            'subjects_counted' => 'integer',
            'subject_scores' => 'array',
            'rank_position' => 'integer',
            'rank_denominator' => 'integer',
            'is_ranked' => 'boolean',
            'computed_at' => 'datetime',
        ];
    }

    /**
     * 10.2 and 10.5: the ranked population. Everything that quotes a
     * denominator, a mean or a pass rate starts from this scope, so "excluded
     * from ranking" and "excluded from the statistics" cannot drift apart.
     *
     * @param  Builder<$this>  $query
     * @return Builder<$this>
     */
    public function scopeRanked(Builder $query): Builder
    {
        return $query->where('is_ranked', true)->whereNotNull('general_average_rounded');
    }

    /**
     * 10.2: a NULL average prints "Non evalue / Not assessed", never 0.00 and
     * never a blank that reads as zero. The label itself is a presentation
     * concern; this predicate is the fact behind it.
     */
    public function isAssessed(): bool
    {
        return $this->general_average_rounded !== null;
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): PeriodResultFactory
    {
        return PeriodResultFactory::new();
    }
}
