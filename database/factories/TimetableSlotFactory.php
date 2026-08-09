<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\Subject;
use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\HR\Models\StaffMember;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * One timetable cell. The class group's academic year is reused so the
 * denormalised `academic_year_id` stays consistent with the FK pair the
 * conflict keys are scoped by.
 *
 * @extends Factory<TimetableSlot>
 */
final class TimetableSlotFactory extends Factory
{
    /** @var class-string<TimetableSlot> */
    protected $model = TimetableSlot::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_group_id' => ClassGroup::factory(),
            'academic_year_id' => function (array $attributes): int {
                $group = ClassGroup::query()->findOrFail($attributes['class_group_id']);

                return $group->academic_year_id;
            },
            'day_of_week' => fake()->numberBetween(1, 6),
            'timetable_period_id' => TimetablePeriod::factory(),
            'subject_id' => Subject::factory(),
            'staff_member_id' => StaffMember::factory(),
            'room_id' => null,
            'effective_from' => '2026-09-01',
            'effective_to' => null,
            'created_by' => User::factory(),
        ];
    }
}
