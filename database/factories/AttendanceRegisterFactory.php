<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Attendance\Domain\AttendanceMode;
use App\Modules\Attendance\Domain\RegisterSession;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an OPEN daily full-day register with a frozen roster of 10 —
 * the only shape OpenAttendanceRegister ever creates; `submitted` and
 * per-lesson shapes are states because every other lifecycle stage is
 * reached from `open`.
 *
 * @extends Factory<AttendanceRegister>
 */
class AttendanceRegisterFactory extends Factory
{
    /** @var class-string<AttendanceRegister> */
    protected $model = AttendanceRegister::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'class_group_id' => ClassGroup::factory(),
            // Kept consistent with the class group's year after resolution —
            // the register denormalises it (§9.3).
            'academic_year_id' => function (array $attributes): int {
                $group = ClassGroup::query()
                    ->whereKey($attributes['class_group_id'])
                    ->firstOrFail();

                return $group->academic_year_id;
            },
            'date' => '2026-09-07',
            'session' => RegisterSession::FullDay,
            'timetable_slot_id' => AttendanceRegister::SLOT_NONE,
            'subject_id' => null,
            'mode' => AttendanceMode::Daily,
            'expected_count' => 10,
            'present_count' => 10,
            'absent_count' => 0,
            'late_count' => 0,
            'excused_count' => 0,
            'status' => RegisterStatus::Open,
            'taken_by' => User::factory(),
            'taken_at' => now(),
        ];
    }

    public function submitted(): self
    {
        return $this->state(fn (): array => [
            'status' => RegisterStatus::Submitted,
            'submitted_at' => now(),
        ]);
    }
}
