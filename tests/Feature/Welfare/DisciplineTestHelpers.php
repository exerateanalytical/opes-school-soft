<?php

declare(strict_types=1);

// Shared fixture builders for the Phase 8 F3 discipline suite. Helper names
// carry the phase8F3 prefix and are function_exists-guarded: Pest loads every
// file in the suite into one process, and a collision with another
// workstream's helpers would be a build break.

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Academics\Models\ClassLevel;
use App\Modules\Academics\Models\SchoolSection;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;

if (! function_exists('phase8F3UserAs')) {
    function phase8F3UserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('phase8F3Fixture')) {
    /**
     * One current year (Sept 2025 - Jun 2026, entirely in the past so
     * incident dates inside it never trip the no-future-incidents rule),
     * one section/level/class group.
     *
     * @return array{
     *     year: AcademicYear,
     *     section: SchoolSection,
     *     level: ClassLevel,
     *     group: ClassGroup,
     * }
     */
    function phase8F3Fixture(): array
    {
        $year = AcademicYear::factory()->current()->create([
            'starts_on' => '2025-09-01',
            'ends_on' => '2026-06-30',
        ]);

        $section = SchoolSection::factory()->create();
        $level = ClassLevel::factory()->create(['school_section_id' => $section->getKey()]);
        $group = ClassGroup::factory()->create([
            'class_level_id' => $level->getKey(),
            'academic_year_id' => $year->getKey(),
            'stream_id' => null,
            'capacity' => 60,
        ]);

        return [
            'year' => $year,
            'section' => $section,
            'level' => $level,
            'group' => $group,
        ];
    }
}

if (! function_exists('phase8F3Enroll')) {
    /**
     * Enrolls one student into the fixture's class group with an open
     * initial segment; returns the enrollment.
     *
     * @param  array{year: AcademicYear, section: SchoolSection, level: ClassLevel, group: ClassGroup}  $fixture
     */
    function phase8F3Enroll(array $fixture, string $enrolledOn = '2025-09-01'): Enrollment
    {
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $fixture['year']->getKey(),
            'class_level_id' => $fixture['level']->getKey(),
            'school_section_id' => $fixture['section']->getKey(),
            'stream_id' => null,
            'status' => 'active',
            'enrolled_on' => $enrolledOn,
        ]);

        EnrollmentSegment::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'class_group_id' => $fixture['group']->getKey(),
            'starts_on' => $enrolledOn,
            'ends_on' => null,
        ]);

        return $enrollment;
    }
}
