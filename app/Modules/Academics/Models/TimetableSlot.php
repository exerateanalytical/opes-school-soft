<?php

declare(strict_types=1);

namespace App\Modules\Academics\Models;

use Database\Factories\TimetableSlotFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One cell of the weekly grid (09-ui §8.6): class group × day × period →
 * subject · teacher · room. The three conflict rules (slot_taken,
 * teacher_busy, room_double_booked) are UNIQUE constraints on the table, not
 * application checks — AssignTimetableSlot translates the violations into
 * domain errors.
 *
 * `staff_member_id` is a plain attribute here, not a relation: StaffMember is
 * an HR model and tests/Architecture/ModuleBoundaryTest.php forbids Academics
 * from importing another module's Models.
 *
 * @property int $id
 * @property int $class_group_id
 * @property int $academic_year_id
 * @property int $day_of_week 1 = Monday … 6 = Saturday
 * @property int $timetable_period_id
 * @property int $subject_id
 * @property int $staff_member_id
 * @property int|null $room_id
 * @property Carbon $effective_from
 * @property Carbon|null $effective_to
 * @property int $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class TimetableSlot extends Model
{
    /** @use HasFactory<TimetableSlotFactory> */
    use HasFactory;

    /** @var list<string> */
    protected $fillable = [
        'class_group_id',
        'academic_year_id',
        'day_of_week',
        'timetable_period_id',
        'subject_id',
        'staff_member_id',
        'room_id',
        'effective_from',
        'effective_to',
        'created_by',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'class_group_id' => 'integer',
            'academic_year_id' => 'integer',
            'day_of_week' => 'integer',
            'timetable_period_id' => 'integer',
            'subject_id' => 'integer',
            'staff_member_id' => 'integer',
            'room_id' => 'integer',
            'effective_from' => 'date',
            'effective_to' => 'date',
            'created_by' => 'integer',
        ];
    }

    /**
     * @return BelongsTo<ClassGroup, $this>
     */
    public function classGroup(): BelongsTo
    {
        return $this->belongsTo(ClassGroup::class);
    }

    /**
     * @return BelongsTo<TimetablePeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(TimetablePeriod::class, 'timetable_period_id');
    }

    /**
     * @return BelongsTo<Subject, $this>
     */
    public function subject(): BelongsTo
    {
        return $this->belongsTo(Subject::class);
    }

    /**
     * @return BelongsTo<Room, $this>
     */
    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    protected static function newFactory(): TimetableSlotFactory
    {
        return TimetableSlotFactory::new();
    }
}
