<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\TimetablePeriodFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * One named row of a section's timetable grid — "Period 1" 07:30–08:20,
 * "BREAK", "LUNCH BREAK" (09-ui §8.6). Bell schedules are per section and
 * school-entered, never seeded (09-ui open question 3).
 *
 * `duration_minutes` is stored because it is the source of heures d'absence
 * for per-lesson attendance (07-students §9.7).
 *
 * @property int $id
 * @property int $school_section_id
 * @property string $name
 * @property string|null $name_fr
 * @property int $sequence
 * @property string $starts_at
 * @property string $ends_at
 * @property bool $is_break
 * @property int $duration_minutes
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TimetablePeriod extends Model
{
    /** @use HasFactory<TimetablePeriodFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'school_section_id',
        'name',
        'name_fr',
        'sequence',
        'starts_at',
        'ends_at',
        'is_break',
        'duration_minutes',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'school_section_id' => 'integer',
            'sequence' => 'integer',
            'is_break' => 'boolean',
            'duration_minutes' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<SchoolSection, $this>
     */
    public function schoolSection(): BelongsTo
    {
        return $this->belongsTo(SchoolSection::class);
    }

    /**
     * @return HasMany<TimetableSlot, $this>
     */
    public function slots(): HasMany
    {
        return $this->hasMany(TimetableSlot::class);
    }

    protected static function newFactory(): TimetablePeriodFactory
    {
        return TimetablePeriodFactory::new();
    }
}
