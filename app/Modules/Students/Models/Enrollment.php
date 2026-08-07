<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\EnrollmentType;
use Database\Factories\EnrollmentFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One row per student per academic year - the object every other module joins
 * to (docs/specs/07-students.md 4).
 *
 * NO relations are declared to AcademicYear, ClassLevel, Stream or
 * SchoolSection. tests/Architecture/ModuleBoundaryTest.php forbids this module
 * from using App\Modules\Academics\Models at all, and its comment is explicit
 * that the rule is now absolute ("No exceptions"). Declaring the relation with
 * a fully-qualified string instead of an import would satisfy the test's
 * letter while defeating its purpose, so the foreign keys are exposed as plain
 * integers and any Academics data an Action needs is read through the query
 * builder, which involves no cross-module class at all.
 *
 * @property int $id
 * @property int $student_id
 * @property int $academic_year_id
 * @property int $class_level_id
 * @property int|null $stream_id
 * @property int $school_section_id
 * @property EnrollmentStatus $status
 * @property bool $is_repeat
 * @property EnrollmentType $enrollment_type
 * @property Carbon $enrolled_on
 * @property Carbon|null $left_on
 * @property int|null $assessable_from_period_id
 * @property int|null $min_periods_assessed
 * @property string $boarding_status
 * @property string|null $previous_school_name
 * @property bool $financial_clearance
 * @property int|null $active_year_key
 * @property int|null $created_by
 * @property int|null $updated_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class Enrollment extends Model
{
    /** @use HasFactory<EnrollmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'student_id',
        'academic_year_id',
        'class_level_id',
        'stream_id',
        'school_section_id',
        'status',
        'is_repeat',
        'enrollment_type',
        'enrolled_on',
        'left_on',
        'assessable_from_period_id',
        'min_periods_assessed',
        'boarding_status',
        'previous_school_name',
        'financial_clearance',
        'created_by',
        'updated_by',
    ];

    // `active_year_key` is absent from $fillable on purpose: it is a STORED
    // GENERATED column (C1, 4.3) and MySQL rejects any write to it.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'student_id' => 'integer',
            'academic_year_id' => 'integer',
            'class_level_id' => 'integer',
            'stream_id' => 'integer',
            'school_section_id' => 'integer',
            'status' => EnrollmentStatus::class,
            'is_repeat' => 'boolean',
            'enrollment_type' => EnrollmentType::class,
            'enrolled_on' => 'date',
            'left_on' => 'date',
            'assessable_from_period_id' => 'integer',
            'min_periods_assessed' => 'integer',
            'financial_clearance' => 'boolean',
            'active_year_key' => 'integer',
        ];
    }

    /**
     * @return HasMany<EnrollmentSegment, $this>
     */
    public function segments(): HasMany
    {
        return $this->hasMany(EnrollmentSegment::class)->orderBy('starts_on');
    }

    /**
     * The at-most-one segment with no end date (uq_segment_open, 5.2).
     *
     * @return HasMany<EnrollmentSegment, $this>
     */
    public function openSegment(): HasMany
    {
        return $this->hasMany(EnrollmentSegment::class)->whereNull('ends_on');
    }

    /**
     * 5.3: "an enrollment's owning class group is the class group of the
     * segment covering P.ends_on". This is the single implementation of that
     * lookup; 01-assessment consumes it for rank and class statistics.
     */
    public function segmentCovering(string $date): ?EnrollmentSegment
    {
        return $this->segments()
            ->where('starts_on', '<=', $date)
            ->where(function (Builder $query) use ($date): void {
                $query->whereNull('ends_on')->orWhere('ends_on', '>=', $date);
            })
            ->orderByDesc('starts_on')
            ->first();
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): EnrollmentFactory
    {
        return EnrollmentFactory::new();
    }
}
