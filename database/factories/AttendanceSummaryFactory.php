<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Modules\Academics\Models\AssessmentPeriod;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AttendanceSummary>
 */
class AttendanceSummaryFactory extends Factory
{
    /** @var class-string<AttendanceSummary> */
    protected $model = AttendanceSummary::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'enrollment_id' => Enrollment::factory(),
            'assessment_period_id' => AssessmentPeriod::factory(),
            'sessions_expected' => 0,
            'sessions_present' => 0,
            'sessions_absent' => 0,
            'sessions_excused' => 0,
            'sessions_late' => 0,
            'sessions_suspended' => 0,
            'hours_absent_justified' => '0.00',
            'hours_absent_unjustified' => '0.00',
            'retards' => 0,
            'computed_at' => now(),
        ];
    }
}
