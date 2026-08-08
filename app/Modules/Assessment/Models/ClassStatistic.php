<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use Database\Factories\ClassStatisticFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * docs/specs/01-assessment.md 10.7, one row per (period, class group, cohort,
 * subject allocation) - with subject_allocation_id = 0 meaning the general
 * average rather than a subject.
 *
 * As with PeriodResult, no relations cross a module boundary: the foreign keys
 * are plain integers and other modules' rows are read through the query
 * builder (tests/Architecture/ModuleBoundaryTest.php).
 *
 * @property int $id
 * @property int $assessment_period_id
 * @property int $class_group_id
 * @property int $subject_allocation_id
 * @property string $cohort_key
 * @property int $n
 * @property string|null $mean
 * @property string|null $min_score
 * @property string|null $max_score
 * @property string|null $median
 * @property string|null $stdev_population
 * @property int $pass_count
 * @property string|null $pass_rate
 * @property Carbon|null $computed_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ClassStatistic extends Model
{
    /** @use HasFactory<ClassStatisticFactory> */
    use HasFactory;

    /**
     * The sentinel that means "the general average, not a subject". See the
     * migration for why this is a sentinel rather than NULL.
     */
    public const GENERAL = 0;

    /** @var list<string> */
    protected $fillable = [
        'assessment_period_id',
        'class_group_id',
        'subject_allocation_id',
        'cohort_key',
        'n',
        'mean',
        'min_score',
        'max_score',
        'median',
        'stdev_population',
        'pass_count',
        'pass_rate',
        'computed_at',
    ];

    /**
     * DECIMAL columns stay strings for the same reason as on PeriodResult:
     * 00-core 7.1 keeps scores off float.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'assessment_period_id' => 'integer',
            'class_group_id' => 'integer',
            'subject_allocation_id' => 'integer',
            'n' => 'integer',
            'pass_count' => 'integer',
            'computed_at' => 'datetime',
        ];
    }

    public function isGeneral(): bool
    {
        return $this->subject_allocation_id === self::GENERAL;
    }

    protected static function newFactory(): ClassStatisticFactory
    {
        return ClassStatisticFactory::new();
    }
}
