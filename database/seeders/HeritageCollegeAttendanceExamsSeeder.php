<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\RebuildAttendanceSummary;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Domain\RegisterSession;
use App\Modules\Identity\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

final class HeritageCollegeAttendanceExamsSeeder extends Seeder
{
    public function run(): void
    {
        $admin = User::query()->where('email', 'demo.admin@opeschool.test')->firstOrFail();
        Auth::login($admin);

        $academicYearId = (int) DB::table('academic_years')->where('code', '2026-2027')->value('id');

        $schoolSectionId = (int) DB::table('school_sections')->value('id');

        $classGroupIds = DB::table('class_groups')
            ->where('academic_year_id', $academicYearId)
            ->limit(18)
            ->pluck('id');

        // Five school weeks, weekdays only, inside the academic year.
        $dates = [];
        $cursor = new \DateTimeImmutable('2026-10-05'); // a Monday
        for ($week = 0; $week < 5; $week++) {
            for ($day = 0; $day < 5; $day++) {
                $dates[] = $cursor->modify("+{$day} days")->format('Y-m-d');
            }
            $cursor = $cursor->modify('+7 days');
        }

        foreach ($dates as $date) {
            if (DB::table('school_calendar_days')
                ->where('academic_year_id', $academicYearId)
                ->where('date', $date)
                ->exists()) {
                continue;
            }

            DB::table('school_calendar_days')->insert([
                'academic_year_id' => $academicYearId,
                'date' => $date,
                'day_type' => 'teaching',
                'school_section_id' => $schoolSectionId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }

        $totalRegisters = 0;
        $totalRecords = 0;

        foreach ($classGroupIds as $classGroupId) {
            $enrollmentIds = DB::table('enrollment_segments')
                ->join('enrollments', 'enrollments.id', '=', 'enrollment_segments.enrollment_id')
                ->where('enrollment_segments.class_group_id', $classGroupId)
                ->where('enrollments.academic_year_id', $academicYearId)
                ->whereNull('enrollment_segments.ends_on')
                ->pluck('enrollments.id')
                ->all();

            if ($enrollmentIds === []) {
                continue;
            }

            // Each student gets a stable "attendance profile" so the same
            // handful of students are chronically absent rather than a fresh
            // random draw every day (realistic - most students are almost
            // always present, a few are not).
            $profile = [];
            foreach ($enrollmentIds as $enrollmentId) {
                $roll = random_int(1, 100);
                $profile[$enrollmentId] = match (true) {
                    $roll <= 70 => 'reliable',   // ~95%+ present
                    $roll <= 92 => 'typical',    // ~85% present
                    default => 'irregular',      // ~65% present
                };
            }

            foreach ($dates as $date) {
                if (DB::table('attendance_registers')
                    ->where('class_group_id', $classGroupId)
                    ->where('date', $date)
                    ->where('session', 'full_day')
                    ->exists()) {
                    continue;
                }

                $register = app(OpenAttendanceRegister::class)->handle(
                    classGroupId: (int) $classGroupId,
                    date: $date,
                    session: RegisterSession::FullDay,
                );

                $marks = [];
                foreach ($enrollmentIds as $enrollmentId) {
                    $absentChance = match ($profile[$enrollmentId]) {
                        'reliable' => 3,
                        'typical' => 12,
                        'irregular' => 30,
                    };

                    $roll = random_int(1, 100);

                    if ($roll <= $absentChance) {
                        $marks[] = [
                            'enrollment_id' => (int) $enrollmentId,
                            'status' => random_int(1, 100) <= 60 ? 'excused' : 'absent',
                        ];
                    } elseif ($roll <= $absentChance + 6) {
                        $marks[] = [
                            'enrollment_id' => (int) $enrollmentId,
                            'status' => 'late',
                            'minutes_late' => random_int(5, 25),
                        ];
                    }
                    // else: present, no row.
                }

                app(SubmitAttendanceRegister::class)->handle((int) $register->id, $marks);
                app(RebuildAttendanceSummary::class)->handle((int) $register->id);

                $totalRegisters++;
                $totalRecords += count($marks);
            }
        }

        $this->command?->info("Attendance: {$totalRegisters} registers, {$totalRecords} exception records.");
    }
}
