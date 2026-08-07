<?php

declare(strict_types=1);

use App\Modules\Academics\Actions\CreateClassGroup;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

function classGroupUserAs(Role $role): User
{
    (new \Database\Seeders\RolePermissionSeeder())->run();
    $user = User::factory()->create(['name' => 'Academics Admin']);
    $user->assignRole($role->value);

    return $user->fresh() ?? $user;
}

/**
 * Prerequisite rows are inserted with DB::table() rather than the
 * AcademicYear/ClassLevel factories: those factories belong to other
 * workstreams and this suite must not depend on their code.
 */
function classGroupAcademicYear(string $code, string $startsOn, string $endsOn): int
{
    return DB::table('academic_years')->insertGetId([
        'code' => $code,
        'name' => "Academic Year {$code}",
        'starts_on' => $startsOn,
        'ends_on' => $endsOn,
        'is_current' => false,
        'status' => 'planned',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

function classGroupClassLevel(string $code = 'F1'): int
{
    $sectionId = DB::table('school_sections')->value('id');

    if (! is_int($sectionId)) {
        $sectionId = DB::table('school_sections')->insertGetId([
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Anglophone General Secondary (First Cycle)',
            'name_fr' => 'Premier cycle secondaire general anglophone',
            'matricule_format' => 'OS-{YY}-{NNNN}',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    return DB::table('class_levels')->insertGetId([
        'school_section_id' => $sectionId,
        'code' => $code,
        'name' => "Form {$code}",
        'name_fr' => "Classe {$code}",
        'order_index' => 1,
        'is_exam_class' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

it('creates a class group', function () {
    actingAs(classGroupUserAs(Role::Administrator));
    $yearId = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $levelId = classGroupClassLevel();

    $group = app(CreateClassGroup::class)->handle(
        classLevelId: $levelId,
        academicYearId: $yearId,
        name: 'Form 1 A',
        capacity: 60,
    );

    expect($group->exists)->toBeTrue()
        ->and($group->name)->toBe('Form 1 A')
        ->and($group->capacity)->toBe(60)
        ->and($group->status)->toBe('active');

    assertDatabaseHas('class_groups', [
        'name' => 'Form 1 A',
        'academic_year_id' => $yearId,
        'class_level_id' => $levelId,
    ]);

    // Every mutation of reference data is audited (00-core 14).
    assertDatabaseHas('audit_logs', [
        'module' => 'Academics',
        'auditable_type' => ClassGroup::class,
        'auditable_id' => $group->getKey(),
        'action' => 'created',
    ]);
});

it('rejects a duplicate name within the same year and level', function () {
    actingAs(classGroupUserAs(Role::Administrator));
    $yearId = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $levelId = classGroupClassLevel();

    $create = app(CreateClassGroup::class);
    $create->handle(classLevelId: $levelId, academicYearId: $yearId, name: 'Form 1 A', capacity: 60);

    expect(fn () => $create->handle(
        classLevelId: $levelId,
        academicYearId: $yearId,
        name: 'Form 1 A',
        capacity: 55,
    ))->toThrow(ValidationException::class);

    expect(ClassGroup::query()->count())->toBe(1);
});

it('allows the same name across different academic years', function () {
    actingAs(classGroupUserAs(Role::Administrator));
    $year1 = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $year2 = classGroupAcademicYear('2027-2028', '2027-09-01', '2028-08-31');
    $levelId = classGroupClassLevel();

    $create = app(CreateClassGroup::class);
    $create->handle(classLevelId: $levelId, academicYearId: $year1, name: 'Form 1 A', capacity: 60);
    $create->handle(classLevelId: $levelId, academicYearId: $year2, name: 'Form 1 A', capacity: 60);

    expect(ClassGroup::query()->where('name', 'Form 1 A')->count())->toBe(2);
});

it('allows the same name in a different level within the same year', function () {
    actingAs(classGroupUserAs(Role::Administrator));
    $yearId = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $level1 = classGroupClassLevel('F1');
    $level2 = classGroupClassLevel('F2');

    $create = app(CreateClassGroup::class);
    $create->handle(classLevelId: $level1, academicYearId: $yearId, name: 'A', capacity: 60);
    $create->handle(classLevelId: $level2, academicYearId: $yearId, name: 'A', capacity: 60);

    expect(ClassGroup::query()->where('name', 'A')->count())->toBe(2);
});

it('rejects a non-positive capacity', function () {
    actingAs(classGroupUserAs(Role::Administrator));
    $yearId = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $levelId = classGroupClassLevel();

    expect(fn () => app(CreateClassGroup::class)->handle(
        classLevelId: $levelId,
        academicYearId: $yearId,
        name: 'Form 1 A',
        capacity: 0,
    ))->toThrow(InvalidArgumentException::class);

    expect(ClassGroup::query()->count())->toBe(0);
});

it('rejects an actor without the academics.manage permission', function () {
    actingAs(classGroupUserAs(Role::Bursar));
    $yearId = classGroupAcademicYear('2026-2027', '2026-09-01', '2027-08-31');
    $levelId = classGroupClassLevel();

    expect(fn () => app(CreateClassGroup::class)->handle(
        classLevelId: $levelId,
        academicYearId: $yearId,
        name: 'Form 1 A',
        capacity: 60,
    ))->toThrow(AuthorizationException::class);

    expect(ClassGroup::query()->count())->toBe(0);
});
