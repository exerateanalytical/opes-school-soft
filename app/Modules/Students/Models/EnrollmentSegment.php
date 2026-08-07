<?php

declare(strict_types=1);

namespace App\Modules\Students\Models;

use App\Modules\Students\Domain\SegmentReason;
use Database\Factories\EnrollmentSegmentFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * C2 (docs/specs/07-students.md 5). The class group and date range of one
 * stretch of an enrollment. Segments partition the enrollment's date range:
 * no overlaps, no gaps.
 *
 * `class_group_id` is an integer, not a relation to
 * App\Modules\Academics\Models\ClassGroup, for the reason set out on
 * Enrollment: tests/Architecture/ModuleBoundaryTest.php forbids this module
 * from using another module's Models, with no exceptions.
 *
 * @property int $id
 * @property int $enrollment_id
 * @property int $class_group_id
 * @property Carbon $starts_on
 * @property Carbon|null $ends_on
 * @property int|null $roll_number
 * @property SegmentReason $reason
 * @property string|null $reason_text
 * @property bool $capacity_override
 * @property int|null $open_key
 * @property int|null $open_roll_group_key
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class EnrollmentSegment extends Model
{
    /** @use HasFactory<EnrollmentSegmentFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'enrollment_id',
        'class_group_id',
        'starts_on',
        'ends_on',
        'roll_number',
        'reason',
        'reason_text',
        'capacity_override',
        'created_by',
    ];

    // `open_key` and `open_roll_group_key` are STORED GENERATED columns (5.2)
    // and are deliberately absent from $fillable: MySQL rejects writes to them.

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'enrollment_id' => 'integer',
            'class_group_id' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'roll_number' => 'integer',
            'reason' => SegmentReason::class,
            'capacity_override' => 'boolean',
            'open_key' => 'integer',
            'open_roll_group_key' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<Enrollment, $this>
     */
    public function enrollment(): BelongsTo
    {
        return $this->belongsTo(Enrollment::class);
    }

    public function isOpen(): bool
    {
        return $this->ends_on === null;
    }

    /**
     * The model lives in a module, not App\Models, so Laravel's factory-name
     * guesser cannot find it. Point at the factory explicitly.
     */
    protected static function newFactory(): EnrollmentSegmentFactory
    {
        return EnrollmentSegmentFactory::new();
    }
}
