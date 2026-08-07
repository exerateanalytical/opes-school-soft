<?php

declare(strict_types=1);

use App\Modules\Guardians\Domain\GuardianRelationship;
use App\Modules\Guardians\Models\Guardian;
use App\Modules\Guardians\Models\StudentGuardian;
use App\Modules\Identity\Domain\Role;
use App\Modules\Students\Domain\DocumentVerificationStatus;
use App\Modules\Students\Domain\MedicalConditionType;
use App\Modules\Students\Domain\MedicalSeverity;
use App\Modules\Students\Livewire\Students\Show;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentDocument;
use App\Modules\Students\Models\StudentMedicalRecord;
use App\Support\Clock\BusinessDate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/* studentsUiUserAs() is declared in StudentsScreenTest.php, guarded. */
if (! function_exists('studentsUiUserAs')) {
    function studentsUiUserAs(Role $role): \App\Modules\Identity\Models\User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = \App\Modules\Identity\Models\User::factory()->create();
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

it('renders through the real route inside the shell', function () {
    actingAs(studentsUiUserAs(Role::Administrator));

    $student = Student::factory()->create(['first_name' => 'Ndeh', 'last_name' => 'Awah']);

    get('/students/'.$student->id)->assertOk()->assertSee('OPES')->assertSee('Ndeh Awah');
});

it('403s on the route for a role without students.view', function () {
    actingAs(studentsUiUserAs(Role::Librarian));

    $student = Student::factory()->create();

    get('/students/'.$student->id)->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    actingAs(studentsUiUserAs(Role::Librarian));

    $student = Student::factory()->create();

    Livewire::test(Show::class, ['student' => $student])->assertForbidden();
});

it('shows the identity block from real columns', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->active()->create([
        'matricule' => 'HA2026-00045',
        'admission_no' => 'ADM/2026/045',
        'first_name' => 'Ndeh',
        'last_name' => 'Awah',
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->assertSee('HA2026-00045')
        ->assertSee('ADM/2026/045')
        ->assertSee(__('opes.students_screen.status_active'));
});

it('never prints the encrypted special-category columns', function () {
    // 00-core 9.5 encrypts these because they are health and belief data about
    // a child, and no staff-side permission narrower than students.view exists
    // yet to gate them. A regression here is a data-protection incident, not a
    // cosmetic one.
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create([
        'blood_group' => 'AB-',
        'genotype' => 'SS',
        'religion' => 'Presbyterian',
        'national_id_number' => '0654876210',
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->assertDontSee('AB-')
        ->assertDontSee('SS')
        ->assertDontSee('Presbyterian')
        ->assertDontSee('0654876210');
});

it('renders the seven unbuilt tabs as present but inert', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    $rendered = Livewire::test(Show::class, ['student' => $student]);

    foreach (Show::DISABLED_TABS as $disabled) {
        $rendered->assertSee(__('opes.students_screen.tab_'.$disabled));
    }

    // Present, aria-disabled, and unreachable: asking for one falls back to
    // General rather than rendering an empty grid that reads as "no marks".
    $rendered->assertSeeHtml('aria-disabled="true"');

    $rendered->call('selectTab', 'fees')->assertSet('tab', 'general');
});

it('shows the linked guardians with relationship, validity and granted scopes', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create(['first_name' => 'Bela', 'last_name' => 'Merceline']);

    StudentGuardian::factory()->primary()->create([
        'student_id' => $student->id,
        'guardian_id' => $guardian->id,
        'relationship' => GuardianRelationship::Mother,
        'receives_reports' => true,
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'guardians')
        ->assertSee('Bela Merceline')
        ->assertSee(__('opes.guardians_screen.relationship_mother'))
        ->assertSee(__('opes.students_screen.validity_current'))
        ->assertSee(__('opes.students_screen.perm_custody'))
        // Resolved through GuardianScopeMatrix, not read off the flag column.
        ->assertSee(__('opes.guardians_screen.scope_results'));
});

it('shows an expired link as ended and granting nothing', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();
    $guardian = Guardian::factory()->create(['first_name' => 'Past', 'last_name' => 'Guardian']);

    StudentGuardian::factory()->expired()->create([
        'student_id' => $student->id,
        'guardian_id' => $guardian->id,
        'has_custody' => true,
        'receives_reports' => true,
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'guardians')
        ->assertSee(__('opes.students_screen.validity_expired'))
        // The flags are still on the row - an operator must see them - but 7.3
        // means they grant nothing today.
        ->assertSee(__('opes.students_screen.perm_custody'))
        ->assertSee(__('opes.guardians_screen.no_effective_scopes'));
});

it('says so instead of showing an empty guardian table', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'guardians')
        ->assertSee(__('opes.students_screen.guardians_empty'));
});

it('lists documents and offers no upload control', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    // No StudentDocumentFactory exists (database/factories belongs to another
    // workstream), and `verification_status` is deliberately out of $fillable
    // - moving a document out of `unverified` is an audited staff decision -
    // so the row is built explicitly rather than through a fill().
    $document = new StudentDocument();
    $document->forceFill([
        'student_id' => $student->id,
        'title' => 'Birth Certificate',
        'file_path' => 'private/students/'.$student->id.'/birth.pdf',
        'file_hash' => str_repeat('a', 64),
        'mime' => 'application/pdf',
        'size_bytes' => 1024,
        'issued_on' => '2012-04-01',
        'verification_status' => DocumentVerificationStatus::Verified,
        'is_archived' => false,
    ])->save();

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'documents')
        ->assertSee('Birth Certificate')
        ->assertSee(__('opes.students_screen.verification_verified'))
        // 8.1 puts the file on a private disk behind a policy-checked
        // controller that does not exist yet, so the control is inert.
        ->assertSee(__('opes.students_screen.upload_disabled'));
});

it('shows the medical summary but never the encrypted detail', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    StudentMedicalRecord::query()->create([
        'student_id' => $student->id,
        'condition_type' => MedicalConditionType::Allergy,
        'summary' => 'Peanut allergy',
        'detail' => 'Carries an adrenaline pen in the school office.',
        'severity' => MedicalSeverity::High,
        'is_emergency_relevant' => true,
        'recorded_at' => Carbon::parse(BusinessDate::today()),
    ]);

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'medical')
        ->assertSee('Peanut allergy')
        ->assertSee(__('opes.students_screen.severity_high'))
        // 8.2 restricts `detail` to Nurse + Administrator; no such staff-side
        // permission exists yet, so it is not rendered at all.
        ->assertDontSee('adrenaline pen');
});

it('says so instead of showing an empty medical table', function () {
    actingAs(studentsUiUserAs(Role::Registrar));

    $student = Student::factory()->create();

    Livewire::test(Show::class, ['student' => $student])
        ->call('selectTab', 'medical')
        ->assertSee(__('opes.students_screen.medical_empty'));
});
