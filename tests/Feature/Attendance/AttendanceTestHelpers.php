<?php

declare(strict_types=1);

// Shared fixture builders for the Phase 8 F2 attendance suite. Helper names
// carry the phase8F2 prefix and are function_exists-guarded: Pest loads every
// file in the suite into one process, and a collision with another
// workstream's helpers would be a build break.

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Academics\Models\Subject;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

if (! function_exists('phase8F2UserAs')) {
    function phase8F2UserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('phase8F2Fixture')) {
    /**
     * One current year (Sept 2026), one section/level/class group, and a
     * seeded calendar for September 2026: Sundays weekend, 2026-09-21 a
     * public holiday, every other day teaching.
     *
     * @return array{
     *     year: AcademicYear,
     *     section: SchoolSection,
     *     level: ClassLevel,
     *     group: ClassGroup,
     * }
     */
    function phase8F2Fixture(): array
    {
        $year = AcademicYear::factory()->current()->create([
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-06-30',
        ]);

        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->getKey()]);
        $group = ClassGroup::factory()->create([
            'class_level_id' => $level->getKey(),
            'academic_year_id' => $year->getKey(),
            'stream_id' => null,
            'capacity' => 60,
        ]);

        $rows = [];
        $cursor = Carbon::parse('2026-09-01');

        while ($cursor->lte(Carbon::parse('2026-09-30'))) {
            $dayType = 'teaching';

            if ($cursor->isSunday()) {
                $dayType = 'weekend';
            } elseif ($cursor->toDateString() === '2026-09-21') {
                $dayType = 'public_holiday';
            }

            $rows[] = [
                'academic_year_id' => (int) $year->getKey(),
                'date' => $cursor->toDateString(),
                'day_type' => $dayType,
                'school_section_id' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ];

            $cursor->addDay();
        }

        DB::table('school_calendar_days')->insert($rows);

        return [
            'year' => $year,
            'section' => $section,
            'level' => $level,
            'group' => $group,
        ];
    }
}

if (! function_exists('phase8F2Enroll')) {
    /**
     * Enrolls $count students into the fixture's class group with open
     * initial segments — the §9.5 roster shape.
     *
     * @param  array{year: AcademicYear, section: SchoolSection, level: ClassLevel, group: ClassGroup}  $fixture
     * @return list<Enrollment>
     */
    function phase8F2Enroll(array $fixture, int $count, string $enrolledOn = '2026-09-01'): array
    {
        $enrollments = [];

        for ($i = 0; $i < $count; $i++) {
            $enrollment = Enrollment::factory()->create([
                'academic_year_id' => $fixture['year']->getKey(),
                'class_level_id' => $fixture['level']->getKey(),
                'school_section_id' => $fixture['section']->getKey(),
                'stream_id' => null,
                'enrolled_on' => $enrolledOn,
            ]);

            EnrollmentSegment::factory()->create([
                'enrollment_id' => $enrollment->getKey(),
                'class_group_id' => $fixture['group']->getKey(),
                'starts_on' => $enrolledOn,
                'ends_on' => null,
            ]);

            $enrollments[] = $enrollment;
        }

        return $enrollments;
    }
}

if (! function_exists('phase8F2Teacher')) {
    /**
     * A Teacher-role user holding a live subject allocation assignment for
     * the fixture's (year, level) — the inner gate OpenAttendanceRegister
     * checks (marks.enter precedent).
     *
     * @param  array{year: AcademicYear, section: SchoolSection, level: ClassLevel, group: ClassGroup}  $fixture
     */
    function phase8F2Teacher(array $fixture): User
    {
        $teacher = phase8F2UserAs(Role::Teacher);

        $subject = Subject::factory()->create();

        $allocationId = DB::table('subject_allocations')->insertGetId([
            'academic_year_id' => (int) $fixture['year']->getKey(),
            'class_level_id' => (int) $fixture['level']->getKey(),
            'stream_id' => 0,
            'subject_id' => (int) $subject->getKey(),
            'coefficient' => '2.00',
            'required_components' => '[]',
            'is_optional' => false,
            'counts_toward_average' => true,
            'is_active' => true,
            'version' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('subject_allocation_teachers')->insert([
            'subject_allocation_id' => $allocationId,
            'user_id' => (int) $teacher->getKey(),
            'assigned_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $teacher;
    }
}

if (! function_exists('phase8F2Period')) {
    /**
     * An assessment period covering September–December 2026 of the
     * fixture's year — the summary rebuild's target.
     *
     * @param  array{year: AcademicYear}  $fixture
     */
    function phase8F2Period(array $fixture): \App\Modules\Academics\Models\AssessmentPeriod
    {
        return \App\Modules\Academics\Models\AssessmentPeriod::factory()->create([
            'academic_year_id' => $fixture['year']->getKey(),
            'code' => 'T1',
            'name' => 'Term 1',
            'name_fr' => 'Trimestre 1',
            'starts_on' => '2026-09-01',
            'ends_on' => '2026-12-15',
            'is_reporting_period' => true,
        ]);
    }
}
