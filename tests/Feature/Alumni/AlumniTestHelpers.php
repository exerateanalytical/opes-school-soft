<?php

declare(strict_types=1);

// Shared fixtures for the Alumni suite. Prefix `alum`, every helper
// function_exists-guarded (00-core test discipline; names must never
// collide with another agent's).

use App\Modules\Identity\Domain\Permission;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\DeriveStudentStatus;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Support\Audit\Actor;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

use function Pest\Laravel\actingAs;

if (! function_exists('alumUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function alumUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('alumManager')) {
    /** The alumni office user: view + manage. */
    function alumManager(): User
    {
        return alumUser(Permission::AlumniView->value, Permission::AlumniManage->value);
    }
}

if (! function_exists('alumUserAs')) {
    /** A signed-in user carrying a seeded ROLE baseline, not ad-hoc grants. */
    function alumUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('alumActor')) {
    function alumActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('alumExitFixture')) {
    /**
     * An academic year with an EXIT-LEVEL class group - the terminal class
     * whose completed enrollments DeriveStudentStatus rule 4 reads as
     * graduated.
     *
     * @return array{year_id: int, year_name: string, section_id: int, level_id: int, group_id: int, group_name: string}
     */
    function alumExitFixture(string $groupName = 'Upper Sixth Science A'): array
    {
        $yearId = DB::table('academic_years')->insertGetId([
            'code' => '2029-2030-'.Str::lower(Str::random(6)),
            'name' => 'Academic Year 2029/2030',
            'starts_on' => '2029-09-01',
            'ends_on' => '2030-06-30',
            'is_current' => false,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $sectionId = DB::table('school_sections')->insertGetId([
            'education_level' => 'secondary_2',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Anglophone General Secondary (Second Cycle) '.Str::upper(Str::random(4)),
            'name_fr' => 'Second cycle secondaire general anglophone '.Str::upper(Str::random(4)),
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 2,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $levelId = DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'US'.Str::upper(Str::random(4)),
            'name' => 'Upper Sixth',
            'name_fr' => 'Terminale',
            'order_index' => 7,
            'is_exam_class' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = DB::table('class_groups')->insertGetId([
            'academic_year_id' => $yearId,
            'class_level_id' => $levelId,
            'stream_id' => null,
            'name' => $groupName,
            'capacity' => 60,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [
            'year_id' => (int) $yearId,
            'year_name' => 'Academic Year 2029/2030',
            'section_id' => (int) $sectionId,
            'level_id' => (int) $levelId,
            'group_id' => (int) $groupId,
            'group_name' => $groupName,
        ];
    }
}

if (! function_exists('alumGraduate')) {
    /**
     * A student whose history genuinely reads as graduated: a COMPLETED
     * enrollment on the exit-level class, its segment closed, and the
     * derived status cache recomputed through the real DeriveStudentStatus
     * door. Returns the student id.
     *
     * @param  array{year_id: int, year_name: string, section_id: int, level_id: int, group_id: int, group_name: string}  $fixture
     */
    function alumGraduate(array $fixture, string $leftOn = '2030-06-15'): int
    {
        $enrollment = Enrollment::factory()->create([
            'academic_year_id' => $fixture['year_id'],
            'class_level_id' => $fixture['level_id'],
            'school_section_id' => $fixture['section_id'],
            'stream_id' => null,
            'status' => 'completed',
            'enrolled_on' => '2029-09-03',
            'left_on' => $leftOn,
        ]);

        EnrollmentSegment::factory()->create([
            'enrollment_id' => $enrollment->getKey(),
            'class_group_id' => $fixture['group_id'],
            'starts_on' => '2029-09-03',
            'ends_on' => $leftOn,
        ]);

        app(DeriveStudentStatus::class)->handle((int) $enrollment->student_id);

        return (int) $enrollment->student_id;
    }
}

if (! function_exists('alumActiveStudent')) {
    /**
     * A student who is NOT a graduate: an active enrollment on a non-exit
     * level in its own (current) year.
     */
    function alumActiveStudent(): int
    {
        $enrollment = Enrollment::factory()->create(['status' => 'active']);

        app(DeriveStudentStatus::class)->handle((int) $enrollment->student_id);

        return (int) $enrollment->student_id;
    }
}
