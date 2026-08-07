<?php

declare(strict_types=1);

use App\Modules\Academics\Models\AcademicYear;
use App\Modules\Academics\Models\ClassGroup;
use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Livewire\Guardians\Show;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
use App\Modules\Students\Models\Student;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/* Shared with the Students UI files; guarded, see StudentsScreenTest.php. */
if (! function_exists('studentsUiUserAs')) {
    function studentsUiUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('renders through the real route inside the shell', function () {
    actingAs(studentsUiUserAs(Role::Administrator));

    $guardian = Guardian::factory()->create(['first_name' => 'Bela', 'last_name' => 'Merceline']);

    get('/guardians/'.$guardian->id)->assertOk()->assertSee('OPES')->assertSee('Bela Merceline');
});

it('403s on the route for a role without students.view', function () {
    // routes/web.php gates guardians.show on students.view: a guardian record
    // is read by whoever may read the student it belongs to.
    actingAs(studentsUiUserAs(Role::Librarian));

    $guardian = Guardian::factory()->create();

    get('/guardians/'.$guardian->id)->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(studentsUiUserAs(Role::Librarian));

    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])->assertForbidden();
});

it('shows the guardian record and the delivery preferences', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create([
        'first_name' => 'Bela',
        'last_name' => 'Merceline',
        'occupation' => 'Business Owner',
        'emergency_contact_name' => 'Ncham Joseph',
        'notify_sms' => true,
    ]);

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->assertSee('Bela Merceline')
        ->assertSee('Business Owner')
        ->assertSee('Ncham Joseph')
        ->assertSee(__('opes.guardians_screen.notify_sms'))
        ->assertSee(__('opes.guardians_screen.status_active'));
});

it('never prints the encrypted ID number', function () {
    // 7.1 encrypts it and 7.7 makes its blind index the duplicate-detection
    // key. This screen is reachable by everyone holding students.view.
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create(['id_number' => '065487621']);

    Livewire::test(Show::class, ['guardian' => $guardian])->assertDontSee('065487621');
});

it('lists linked children with class, admission number and granted scopes', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();

    $year = AcademicYear::factory()->current()->create();
    $group = ClassGroup::factory()->create(['academic_year_id' => $year->id, 'name' => 'Form 1A']);

    $student = Student::factory()->active()->create([
        'first_name' => 'Ncham',
        'last_name' => 'Bela',
        'admission_no' => 'HA20260078',
    ]);

    $enrollment = Enrollment::factory()->create([
        'student_id' => $student->id,
        'academic_year_id' => $year->id,
    ]);

    EnrollmentSegment::factory()->create([
        'enrollment_id' => $enrollment->id,
        'class_group_id' => $group->id,
    ]);

    StudentGuardian::factory()->primary()->create([
        'student_id' => $student->id,
        'guardian_id' => $guardian->id,
        'relationship' => GuardianRelationship::Mother,
        'receives_invoices' => true,
    ]);

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->assertSee('Ncham Bela')
        ->assertSee('HA20260078')
        ->assertSee('Form 1A')
        ->assertSee(__('opes.guardians_screen.relationship_mother'))
        ->assertSee(__('opes.students_screen.validity_current'))
        // 11.3 adds this column to the mockup on purpose, and it is resolved
        // through GuardianScopeMatrix rather than read off the flag column.
        ->assertSee(__('opes.guardians_screen.scope_fees'));
});

it('shows an ended link rather than hiding it', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();
    $student = Student::factory()->create(['first_name' => 'Was', 'last_name' => 'Linked']);

    StudentGuardian::factory()->expired()->create([
        'student_id' => $student->id,
        'guardian_id' => $guardian->id,
        'has_custody' => true,
    ]);

    // 7.2 has no hard delete, and an operator asking "why can this person
    // still see the fees" needs the row that answers it.
    Livewire::test(Show::class, ['guardian' => $guardian])
        ->assertSee('Was Linked')
        ->assertSee(__('opes.students_screen.validity_expired'))
        ->assertSee(__('opes.guardians_screen.no_effective_scopes'));
});

it('says so instead of showing an empty linked-students table', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->assertSee(__('opes.guardians_screen.linked_empty'));
});

it('gives meetings and communications real empty states', function () {
    // Both tables exist (7.8); nothing writes to them in Phase 2, and 7.8 says
    // an empty queue on a disconnected LAN is the normal steady state - so the
    // screen states that rather than showing a placeholder grid.
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->call('selectTab', 'meetings')
        ->assertSee(__('opes.guardians_screen.meetings_empty'))
        ->call('selectTab', 'communications')
        ->assertSee(__('opes.guardians_screen.communications_empty'));
});

it('renders the three unbuilt tabs as present but inert', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();

    $rendered = Livewire::test(Show::class, ['guardian' => $guardian]);

    foreach (Show::DISABLED_TABS as $disabled) {
        $rendered->assertSee(__('opes.guardians_screen.tab_'.$disabled));
    }

    $rendered->assertSeeHtml('aria-disabled="true"')
        ->call('selectTab', 'payments')
        ->assertSet('tab', 'linked_students');
});

it('lists a meeting and a communication when the tables carry rows', function () {
    // Guards the render path itself: an empty-state-only test would never
    // exercise the enum labels or the date formatting, so a broken row would
    // only surface once the Communication module started writing.
    actingAs(studentsUiUserAs(Role::Registrar));

    $guardian = Guardian::factory()->create();

    \App\Modules\Guardians\Models\GuardianMeeting::factory()->create([
        'guardian_id' => $guardian->id,
    ]);

    \App\Modules\Guardians\Models\GuardianCommunication::factory()->create([
        'guardian_id' => $guardian->id,
        'subject' => 'Term 1 report card',
    ]);

    Livewire::test(Show::class, ['guardian' => $guardian])
        ->call('selectTab', 'meetings')
        ->assertDontSee(__('opes.guardians_screen.meetings_empty'))
        ->call('selectTab', 'communications')
        ->assertSee('Term 1 report card');
});
