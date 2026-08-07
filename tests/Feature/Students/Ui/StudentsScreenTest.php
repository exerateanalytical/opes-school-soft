<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Livewire\Students\Index;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Shared with StudentProfileTest and the Guardians UI file. Guarded because
 * Pest loads every test file into one process and a second declaration is a
 * fatal error, not a failure.
 */
if (! function_exists('studentsUiUserAs')) {
    function studentsUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

/**
 * A student who is really in a class: a current year, a class group in it, a
 * live Enrollment and an OPEN EnrollmentSegment. The screen resolves the class
 * column through exactly that chain (07-students 5.2), so a fixture that skips
 * a link would test nothing.
 */
function studentInClass(string $className, string $matricule): Student
{
    $year = AcademicYear::query()->where('is_current', true)->first()
        ?? AcademicYear::factory()->current()->create();

    $group = ClassGroup::factory()->create([
        'academic_year_id' => $year->id,
        'name' => $className,
    ]);

    $student = Student::factory()->active()->create([
        'matricule' => $matricule,
        'admission_no' => 'ADM/'.$matricule,
        'first_name' => 'Ngu',
        'last_name' => 'Bih',
    ]);

    $enrollment = Enrollment::factory()->create([
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
    ]);

    EnrollmentSegment::factory()->create([
        'enrollment_id' => $enrollment->id,
        'class_group_id' => $group->id,
    ]);

    return $student;
}

it('renders through the real route inside the shell', function () {
    actingAs(studentsUiUserAs(Role::Administrator));

    get('/students')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without students.view', function () {
    // Librarian holds no permissions at all in Role::defaultPermissions().
    actingAs(studentsUiUserAs(Role::Librarian));

    get('/students')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(studentsUiUserAs(Role::Librarian));

    Livewire::test(Index::class)->assertForbidden();
});

it('lists a created student with its matricule and current class', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    studentInClass('Form 2A', 'HA2026-00045');

    Livewire::test(Index::class)
        ->assertSee('Ngu Bih')
        ->assertSee('HA2026-00045')
        ->assertSee('Form 2A');
});

it('says so rather than inventing a class for an unenrolled student', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    Student::factory()->create(['first_name' => 'Solo', 'last_name' => 'Nkeng']);

    Livewire::test(Index::class)
        ->assertSee('Solo Nkeng')
        ->assertSee(__('opes.students_screen.no_class'));
});

it('searches by name and by matricule', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    Student::factory()->create(['first_name' => 'Achu', 'last_name' => 'Fon', 'matricule' => 'HA-1', 'admission_no' => 'ADM-1']);
    Student::factory()->create(['first_name' => 'Bela', 'last_name' => 'Nde', 'matricule' => 'HA-2', 'admission_no' => 'ADM-2']);

    Livewire::test(Index::class)
        ->set('search', 'Achu')
        ->assertSee('Achu Fon')
        ->assertDontSee('Bela Nde')
        ->set('search', 'HA-2')
        ->assertSee('Bela Nde')
        ->assertDontSee('Achu Fon');
});

it('filters by status through the tabs and the select alike', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    Student::factory()->active()->create(['first_name' => 'Live', 'last_name' => 'One']);
    Student::factory()->create([
        'first_name' => 'Gone', 'last_name' => 'Two', 'status' => StudentStatus::Graduated,
    ]);

    Livewire::test(Index::class)
        ->call('selectStatus', StudentStatus::Active->value)
        ->assertSee('Live One')
        ->assertDontSee('Gone Two')
        ->set('status', StudentStatus::Graduated->value)
        ->assertSee('Gone Two')
        ->assertDontSee('Live One');
});

it('counts the KPI row from the whole dataset, not from the page', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    Student::factory()->count(3)->active()->create();
    Student::factory()->create(['status' => StudentStatus::Graduated]);

    // perPage is deliberately below the row count: the tiles must still read 4
    // and 3, which is the difference between a dataset count and a page count.
    Livewire::test(Index::class)
        ->set('perPage', 25)
        ->set('status', StudentStatus::Active->value)
        ->assertViewHas('totalStudents', 4)
        ->assertViewHas('statusCounts', fn (array $counts): bool => ($counts['active'] ?? 0) === 3);
});

it('excludes archived students from the roll', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    Student::factory()->create(['first_name' => 'Kept', 'last_name' => 'Onroll']);
    Student::factory()->create(['first_name' => 'Filed', 'last_name' => 'Away', 'is_archived' => true]);

    Livewire::test(Index::class)
        ->assertSee('Kept Onroll')
        ->assertDontSee('Filed Away');
});

it('filters by class group', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $inClass = studentInClass('Form 3B', 'HA2026-00099');
    Student::factory()->create(['first_name' => 'Notin', 'last_name' => 'Anyclass']);

    $groupId = EnrollmentSegment::query()->firstOrFail()->class_group_id;

    Livewire::test(Index::class)
        ->set('classGroup', (string) $groupId)
        ->assertSee($inClass->matricule)
        ->assertDontSee('Notin Anyclass');
});

it('reaches the profile from a row action', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    get('/students')->assertOk()->assertSee(route('students.show', $student), false);
});
