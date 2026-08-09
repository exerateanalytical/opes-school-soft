<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Attendance\Domain\AttendanceStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Defaults to an unjustified `absent` — the archetypal EXCEPTION row.
 * `present` is deliberately not a state: §9.4's storage rule never writes
 * present rows, and a factory that could would normalise the defect the
 * whole design removes.
 *
 * @extends Factory<AttendanceRecord>
 */
class AttendanceRecordFactory extends Factory
{
    /** @var class-string<AttendanceRecord> */
    protected $model = AttendanceRecord::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'attendance_register_id' => AttendanceRegister::factory(),
            'enrollment_id' => Enrollment::factory(),
            'status' => AttendanceStatus::Absent,
            'is_justified' => false,
            'justification_type' => null,
            'justification_document_id' => null,
            'justified_by' => null,
            'justified_at' => null,
            'minutes_late' => null,
            'remark' => null,
            'recorded_by' => User::factory(),
        ];
    }

    public function late(int $minutes = 10): self
    {
        return $this->state(fn (): array => [
            'status' => AttendanceStatus::Late,
            'minutes_late' => $minutes,
        ]);
    }
}
