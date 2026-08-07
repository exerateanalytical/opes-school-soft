<?php

declare(strict_types=1);

use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Livewire\Wizard;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\get;

uses(RefreshDatabase::class);

/*
 * Shared with AdmissionFlowTest. Pest loads every file in the suite into one
 * process, so a second unguarded declaration of the same helper is a fatal
 * redeclare - the guard is what lets the two files be read independently.
 */
if (! function_exists('admissionsUserAs')) {
    function admissionsUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder())->run();
        $user = User::factory()->create(['name' => 'Admissions Clerk']);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('admissionsFixture')) {
    /**
     * @return array{section: int, level: int, year: int, group: int}
     */
    function admissionsFixture(): array
    {
        $sectionId = (int) DB::table('school_sections')->insertGetId([
            'education_level' => 'secondary_1',
            'track' => 'general',
            'sub_system' => 'anglophone',
            'name' => 'Anglophone General Secondary (First Cycle)',
            'name_fr' => 'Premier cycle secondaire general anglophone',
            'matricule_format' => 'HA/{year}/{seq:4}',
            'display_order' => 1,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $levelId = (int) DB::table('class_levels')->insertGetId([
            'school_section_id' => $sectionId,
            'code' => 'F1',
            'name' => 'Form 1',
            'name_fr' => 'Sixieme',
            'order_index' => 1,
            'is_exam_class' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $yearId = (int) DB::table('academic_years')->insertGetId([
            'code' => '2026-2027',
            'name' => 'Academic Year 2026/2027',
            'starts_on' => '2026-09-01',
            'ends_on' => '2027-08-31',
            'is_current' => true,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $groupId = (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => $levelId,
            'stream_id' => null,
            'academic_year_id' => $yearId,
            'name' => 'Form 1 A',
            'class_teacher_staff_id' => null,
            'room_id' => null,
            'capacity' => 60,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['section' => $sectionId, 'level' => $levelId, 'year' => $yearId, 'group' => $groupId];
    }
}

if (! function_exists('admissionsWizardThroughStepFour')) {
    /**
     * Drive the component through steps 1-4, exactly as an operator would.
     *
     * @param  array{section: int, level: int, year: int, group: int}  $fixture
     * @return \Livewire\Features\SupportTesting\Testable<Wizard>
     */
    function admissionsWizardThroughStepFour(array $fixture): \Livewire\Features\SupportTesting\Testable
    {
        return Livewire::test(Wizard::class)
            ->set('first_name', 'Ncham')
            ->set('last_name', 'Bela')
            ->set('date_of_birth', '2012-03-15')
            ->set('gender', 'male')
            ->set('nationality', 'CM')
            ->call('next')
            ->set('academic_year_id', (string) $fixture['year'])
            ->set('class_level_id', (string) $fixture['level'])
            ->set('admission_date', '2026-09-05')
            ->call('next')
            ->set('guardians.0.first_name', 'Merceline')
            ->set('guardians.0.last_name', 'Bela')
            ->set('guardians.0.gender', 'female')
            ->set('guardians.0.relationship', 'mother')
            ->set('guardians.0.phone', '677112233')
            ->call('next')
            ->set('previous_school_name', 'Government Bilingual Primary School')
            ->call('next');
    }
}

it('renders through the real route inside the app shell', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    get('/admissions')->assertOk()->assertSee('OPES');
});

it('403s on the route for a role without admissions.manage', function () {
    actingAs(admissionsUserAs(Role::Bursar));

    get('/admissions')->assertForbidden();
});

it('forbids reaching the component directly without permission', function () {
    // Hiding the sidebar link is presentation, never a control (00-core 6.2):
    // the component has to refuse on its own.
    actingAs(admissionsUserAs(Role::Bursar));

    Livewire::test(Wizard::class)->assertForbidden();
});

it('shows the five steps with the current one marked', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    Livewire::test(Wizard::class)
        ->assertSee(__('opes.admissions_screen.steps.basic_information'))
        ->assertSee(__('opes.admissions_screen.steps.academic_details'))
        ->assertSee(__('opes.admissions_screen.steps.parent_guardian'))
        ->assertSee(__('opes.admissions_screen.steps.other_information'))
        ->assertSee(__('opes.admissions_screen.steps.documents_review'))
        ->assertSeeHtml('aria-current="step"');
});

it('blocks progression and shows the error inline when step 1 is incomplete', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    Livewire::test(Wizard::class)
        ->set('first_name', 'Ncham')
        ->call('next')
        ->assertHasErrors(['last_name', 'date_of_birth', 'gender'])
        ->assertSet('step', WizardStep::BasicInformation->value);

    expect(AdmissionApplication::query()->count())->toBe(0);
});

it('saves a draft on Next and advances', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    Livewire::test(Wizard::class)
        ->set('first_name', 'Ncham')
        ->set('last_name', 'Bela')
        ->set('date_of_birth', '2012-03-15')
        ->set('gender', 'male')
        ->call('next')
        ->assertHasNoErrors()
        ->assertSet('step', WizardStep::AcademicDetails->value);

    assertDatabaseHas('admission_applications', [
        'last_name' => 'Bela',
        'status' => ApplicationStatus::Draft->value,
        'current_step' => WizardStep::AcademicDetails->value,
    ]);
});

it('resumes the saved step after a reload', function () {
    // 6.2: "a power cut loses at most one step". Driven through the REAL page
    // load rather than Livewire::withQueryParams, because a reload is exactly
    // what is being tested - a fresh HTTP request that has only the query
    // string and the row to work from.
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    admissionsWizardThroughStepFour($fixture);

    $application = AdmissionApplication::query()->firstOrFail();

    expect($application->current_step)->toBe(WizardStep::DocumentsReview->value);

    get('/admissions?application='.$application->getKey())
        ->assertOk()
        // Step 5's own heading, so this asserts the STEP resumed and not
        // merely that the row was found.
        ->assertSee(__('opes.admissions_screen.section_review'))
        // ...and the saved values are back, including the step 3 guardian.
        ->assertSee('Ncham')
        ->assertSee('Merceline')
        ->assertSee('Government Bilingual Primary School');
});

it('starts a fresh draft rather than failing on a stale application id', function () {
    // The operator asked for the wizard, not for one particular record, so an
    // invented id starts a new draft instead of 404-ing at them.
    actingAs(admissionsUserAs(Role::Registrar));

    get('/admissions?application=9999')
        ->assertOk()
        ->assertSee(__('opes.admissions_screen.section_personal'))
        ->assertDontSee(__('opes.admissions_screen.section_review'));
});

it('shows everything entered on the review step', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    admissionsWizardThroughStepFour($fixture)
        ->assertSet('step', WizardStep::DocumentsReview->value)
        ->assertSee('Ncham')
        ->assertSee('Bela')
        ->assertSee('Merceline')
        ->assertSee('2012-03-15')
        ->assertSee('Government Bilingual Primary School')
        ->assertSee('Form 1')
        ->assertSee('2026-2027');
});

it('converts on confirm and refuses a second confirm', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $component = admissionsWizardThroughStepFour($fixture)
        ->call('submit')
        ->assertHasNoErrors()
        ->set('class_group_id', (string) $fixture['group'])
        ->call('confirm')
        ->assertHasNoErrors();

    $application = AdmissionApplication::query()->firstOrFail();

    expect($application->status)->toBe(ApplicationStatus::Enrolled)
        ->and($application->converted_student_id)->not->toBeNull();

    assertDatabaseHas('enrollments', ['student_id' => $application->converted_student_id]);
    assertDatabaseHas('student_guardians', ['student_id' => $application->converted_student_id]);

    // Pressing Confirm twice is an ordinary human action; it must read as a
    // refusal, not a 500.
    $component->call('confirm')->assertHasErrors(['class_group_id']);

    expect(DB::table('students')->count())->toBe(1);
});

it('requires a class group before it will convert', function () {
    // 6.3 step 4: the application named only a class LEVEL, so the group is a
    // decision that has to be made here rather than guessed.
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    admissionsWizardThroughStepFour($fixture)
        ->call('submit')
        ->call('confirm')
        ->assertHasErrors(['class_group_id']);

    expect(DB::table('students')->count())->toBe(0);
});

it('stops editing a submitted application', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    admissionsWizardThroughStepFour($fixture)
        ->call('submit')
        ->assertSee(__('opes.admissions_screen.submitted_notice'));
});
