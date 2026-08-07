<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Domain\AssessmentPeriodStatus;
use App\Modules\Academics\Domain\AssessmentPeriodType;
use Database\Factories\AssessmentPeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A node of the per-year assessment-period tree (01-assessment 4.1).
 *
 * `framework_id` is a plain nullable column until the Assessment phase
 * delivers the frameworks table; Phase 1 rows leave it NULL.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int|null $framework_id
 * @property int|null $parent_id
 * @property AssessmentPeriodType $type
 * @property string $code
 * @property string $name
 * @property string $name_fr
 * @property int $order_index
 * @property Carbon $starts_on
 * @property Carbon $ends_on
 * @property string $weight
 * @property bool $counts_toward_parent
 * @property Carbon|null $marks_entry_opens_at
 * @property Carbon|null $marks_entry_closes_at
 * @property bool $is_reporting_period
 * @property AssessmentPeriodStatus $status
 */
final class AssessmentPeriod extends Model
{
    /** @use HasFactory<AssessmentPeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id', 'framework_id', 'parent_id', 'type', 'code',
        'name', 'name_fr', 'order_index', 'starts_on', 'ends_on', 'weight',
        'counts_toward_parent', 'marks_entry_opens_at', 'marks_entry_closes_at',
        'is_reporting_period', 'status',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => AssessmentPeriodType::class,
            'status' => AssessmentPeriodStatus::class,
            'starts_on' => 'date',
            'ends_on' => 'date',
            // decimal cast keeps the value a string: 00-core 7 forbids float
            // arithmetic on anything that feeds the grading pipeline.
            'weight' => 'decimal:4',
            'counts_toward_parent' => 'boolean',
            'is_reporting_period' => 'boolean',
            'marks_entry_opens_at' => 'datetime',
            'marks_entry_closes_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AssessmentPeriodFactory
    {
        return AssessmentPeriodFactory::new();
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<AssessmentPeriod, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<AssessmentPeriod, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }
}
