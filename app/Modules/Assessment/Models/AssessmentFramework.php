<?php

declare(strict_types=1);

namespace App\Modules\Assessment\Models;

use App\Modules\Assessment\Domain\FrameworkFamily;
use App\Modules\Assessment\Domain\MissingComponentPolicy;
use Database\Factories\AssessmentFrameworkFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * How assessment works for one SchoolSection in one AcademicYear
 * (docs/specs/01-assessment.md 3.1).
 *
 * There is deliberately NO belongsTo for `school_section_id` or
 * `academic_year_id`: those rows belong to the Academics module and 00-core 6.2
 * rule 2 forbids reaching into another module's Models. Callers that need the
 * section resolve it through Academics' own Actions, or read it with the query
 * builder.
 *
 * @property int $id
 * @property int $school_section_id
 * @property int $academic_year_id
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property FrameworkFamily $family
 * @property string $assessment_mode
 * @property numeric-string $max_score
 * @property numeric-string $pass_score
 * @property int $score_precision
 * @property bool $uses_coefficients
 * @property bool $uses_rank
 * @property string $rank_scope
 * @property string $rank_cohort_rule
 * @property string $annual_composition
 * @property bool $requires_conseil
 * @property bool $requires_hod_validation
 * @property bool $requires_per_lesson_attendance
 * @property MissingComponentPolicy $missing_component_policy
 * @property int $min_periods_assessed
 * @property int|null $gpa_scale_id
 * @property bool $is_default
 * @property bool $is_active
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class AssessmentFramework extends Model
{
    /** @use HasFactory<AssessmentFrameworkFactory> */
    use HasFactory;

    public const MODE_NUMERIC = 'numeric';

    public const MODE_COMPETENCY = 'competency';

    public const MODE_HYBRID = 'hybrid';

    /**
     * The closed sets of 01-assessment 3.1 that are NOT modelled as Domain
     * enums. Only family and missing_component_policy earned enums, because
     * only they carry behaviour; the rest are configuration the pipeline
     * branches on by value, and three more single-purpose enums would be
     * ceremony. They are validated against these lists at save.
     *
     * @var list<string>
     */
    public const MODES = [self::MODE_NUMERIC, self::MODE_COMPETENCY, self::MODE_HYBRID];

    /** @var list<string> */
    public const RANK_SCOPES = ['class_group', 'class_level', 'stream'];

    /** @var list<string> */
    public const RANK_COHORT_RULES = ['identical_basket', 'same_stream', 'all'];

    /** @var list<string> */
    public const ANNUAL_COMPOSITIONS = ['mean_of_leaf_periods', 'weighted_children', 'mean_of_terms'];

    /** @var list<string> */
    protected $fillable = [
        'school_section_id',
        'academic_year_id',
        'code',
        'name',
        'name_fr',
        'family',
        'assessment_mode',
        'max_score',
        'pass_score',
        'score_precision',
        'uses_coefficients',
        'uses_rank',
        'rank_scope',
        'rank_cohort_rule',
        'annual_composition',
        'requires_conseil',
        'requires_hod_validation',
        'requires_per_lesson_attendance',
        'missing_component_policy',
        'min_periods_assessed',
        'gpa_scale_id',
        'is_default',
        'is_active',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_section_id' => 'int',
            'academic_year_id' => 'int',
            'family' => FrameworkFamily::class,
            'max_score' => 'decimal:3',
            'pass_score' => 'decimal:3',
            'score_precision' => 'int',
            'uses_coefficients' => 'bool',
            'uses_rank' => 'bool',
            'requires_conseil' => 'bool',
            'requires_hod_validation' => 'bool',
            'requires_per_lesson_attendance' => 'bool',
            'missing_component_policy' => MissingComponentPolicy::class,
            'min_periods_assessed' => 'int',
            'gpa_scale_id' => 'int',
            'is_default' => 'bool',
            'is_active' => 'bool',
        ];
    }

    protected static function newFactory(): AssessmentFrameworkFactory
    {
        return AssessmentFrameworkFactory::new();
    }

    /**
     * @return HasMany<GradeBand, $this>
     */
    public function gradeBands(): HasMany
    {
        return $this->hasMany(GradeBand::class, 'framework_id');
    }

    /**
     * @return HasMany<AssessmentComponent, $this>
     */
    public function components(): HasMany
    {
        return $this->hasMany(AssessmentComponent::class, 'framework_id');
    }

    /**
     * The top of the band ladder for a given basis (01-assessment 3.3 clause
     * 3). A percentage basis always tops out at 100 regardless of the
     * framework's own scale - mixing the two with no column saying which is
     * exactly the v1 defect that printed blank grades.
     */
    public function scaleCeiling(string $scaleBasis): string
    {
        return $scaleBasis === GradeBand::BASIS_PERCENTAGE
            ? '100.000'
            : $this->max_score;
    }
}
