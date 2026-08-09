<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use App\Modules\Academics\Domain\CalendarDayType;
use Database\Factories\SchoolCalendarDayFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One date of one academic year (07-students §9.2). Every date inside the
 * year resolves to exactly one row per section — section-specific rows win
 * over the SECTION_ALL sentinel row. A missing calendar BLOCKS register
 * creation; it never defaults to "teaching".
 *
 * @property int $id
 * @property int $academic_year_id
 * @property Carbon $date
 * @property CalendarDayType $day_type
 * @property int $school_section_id
 * @property string|null $label
 * @property string|null $label_fr
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SchoolCalendarDay extends Model
{
    /** @use HasFactory<SchoolCalendarDayFactory> */
    use HasFactory;

    /**
     * The school_section_id sentinel meaning "all sections". Deliberately 0
     * and NOT NULL — MySQL permits unlimited duplicate NULLs in a UNIQUE
     * index, which would let the same (year, date) be entered twice for the
     * whole school (the 04-fees NULL-in-UNIQUE trap).
     */
    public const SECTION_ALL = 0;

    /** @var list<string> */
    protected $fillable = [
        'academic_year_id',
        'date',
        'day_type',
        'school_section_id',
        'label',
        'label_fr',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'academic_year_id' => 'integer',
            'date' => 'date',
            'day_type' => CalendarDayType::class,
            'school_section_id' => 'integer',
        ];
    }

    public function isForAllSections(): bool
    {
        return $this->school_section_id === self::SECTION_ALL;
    }

    /**
     * @return BelongsTo<AcademicYear, $this>
     */
    public function academicYear(): BelongsTo
    {
        return $this->belongsTo(AcademicYear::class);
    }

    protected static function newFactory(): SchoolCalendarDayFactory
    {
        return SchoolCalendarDayFactory::new();
    }
}
