<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\SubjectAllocationFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A subject placed on a class list for ONE academic year, with the
 * coefficient and grading configuration every Mark row will be permanently
 * bound to (01-assessment 5.1, 5.2).
 *
 * `stream_id` uses the 0 sentinel, never NULL - see STREAM_NONE.
 *
 * @property int $id
 * @property int $academic_year_id
 * @property int $class_level_id
 * @property int $stream_id
 * @property int $subject_id
 * @property numeric-string $coefficient
 * @property int|null $subject_group_id
 * @property list<int> $required_components
 * @property numeric-string|null $max_score_override
 * @property bool $is_optional
 * @property bool $counts_toward_average
 * @property int|null $effective_from_period_id
 * @property int|null $effective_to_period_id
 * @property bool $is_active
 * @property int $version
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SubjectAllocation extends Model
{
    /** @use HasFactory<SubjectAllocationFactory> */
    use HasFactory;

    /**
     * The stream_id sentinel meaning "no stream / whole level". Deliberately
     * 0 and NOT NULL: MySQL permits unlimited duplicate NULLs in a UNIQUE
     * index, which would let the same subject be allocated twice to a
     * stream-less level (01-assessment 5.1).
     */
    public const STREAM_NONE = 0;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'class_level_id',
        'stream_id',
        'subject_id',
        'coefficient',
        'subject_group_id',
        'required_components',
        'max_score_override',
        'is_optional',
        'counts_toward_average',
        'effective_from_period_id',
        'effective_to_period_id',
        'is_active',
        'version',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'int',
            'class_level_id' => 'int',
            'stream_id' => 'int',
            'subject_id' => 'int',
            'coefficient' => 'decimal:2',
            'subject_group_id' => 'int',
            'required_components' => 'array',
            'max_score_override' => 'decimal:3',
            'is_optional' => 'bool',
            'counts_toward_average' => 'bool',
            'effective_from_period_id' => 'int',
            'effective_to_period_id' => 'int',
            'is_active' => 'bool',
            'version' => 'int',
        ];
    }

    protected static function newFactory(): SubjectAllocationFactory
    {
        return SubjectAllocationFactory::new();
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    /**
     * @return BelongsTo<ClassLevel, $this>
     */
    public function classLevel(): BelongsTo
    {
        return $this->belongsTo(ClassLevel::class);
    }

    public function isForWholeLevel(): bool
    {
        return $this->stream_id === self::STREAM_NONE;
    }
}
