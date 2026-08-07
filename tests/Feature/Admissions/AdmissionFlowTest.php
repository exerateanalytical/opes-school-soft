<?php

declare(strict_types=1);

use App\Modules\Admissions\Actions\ConvertApplication;
use App\Modules\Admissions\Actions\RejectApplication;
use App\Modules\Admissions\Actions\SaveApplicationStep;
use App\Modules\Admissions\Actions\SubmitApplication;
use App\Modules\Admissions\Domain\ApplicationStatus;
use App\Modules\Admissions\Domain\WizardStep;
use App\Modules\Admissions\Models\AdmissionApplication;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;
use function Pest\Laravel\assertDatabaseMissing;

uses(RefreshDatabase::class);

/* Shared with WizardScreenTest - see the guard's note there. */
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
     * Prerequisite rows are inserted with DB::table() rather than the
     * Academics and Students factories: those belong to other workstreams and
     * this suite must not depend on their code to stay green.
     *
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

if (! function_exists('admissionsStepOne')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function admissionsStepOne(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Ncham',
            'middle_name' => 'Andre',
            'last_name' => 'Bela',
            'date_of_birth' => '2012-03-15',
            'gender' => 'male',
            'nationality' => 'CM',
            'place_of_birth' => 'Douala',
            'state_of_origin' => 'Littoral',
            'religion' => 'Christianity',
            'blood_group' => 'O+',
            'genotype' => 'AA',
        ], $overrides);
    }
}

if (! function_exists('admissionsGuardianRow')) {
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    function admissionsGuardianRow(array $overrides = []): array
    {
        return array_merge([
            'first_name' => 'Merceline',
            'last_name' => 'Bela',
            'gender' => 'female',
            'date_of_birth' => '1985-06-02',
            'relationship' => 'mother',
            'is_primary' => true,
            'phone' => '677112233',
            'email' => 'merceline@example.test',
            'language' => 'en',
            'has_custody' => true,
            'receives_reports' => true,
            'receives_invoices' => true,
            'is_emergency_contact' => true,
            'is_authorised_for_pickup' => true,
            'is_fee_payer' => true,
        ], $overrides);
    }
}

if (! function_exists('admissionsReload')) {
    /** Re-read a row from the database with its concrete type intact. */
    function admissionsReload(int $id): AdmissionApplication
    {
        return AdmissionApplication::query()->where('id', '=', $id)->firstOrFail();
    }
}

if (! function_exists('admissionsCompleteDraft')) {
    /**
     * Walk steps 1-4 and return the resulting draft.
     *
     * @param  array{section: int, level: int, year: int, group: int}  $fixture
     */
    function admissionsCompleteDraft(array $fixture): AdmissionApplication
    {
        $save = app(SaveApplicationStep::class);

        $application = $save->handle(WizardStep::BasicInformation, admissionsStepOne());

        $application = $save->handle(WizardStep::AcademicDetails, [
            'academic_year_id' => $fixture['year'],
            'school_section_id' => $fixture['section'],
            'class_level_id' => $fixture['level'],
            'admission_date' => '2026-09-05',
        ], $application);

        $application = $save->handle(WizardStep::ParentGuardian, [
            'guardians' => [admissionsGuardianRow()],
        ], $application);

        return $save->handle(WizardStep::OtherInformation, [
            'previous_school_name' => 'Government Bilingual Primary School',
            'last_class_completed' => 'Primary 6',
            'year_completed' => 2026,
            'reason_for_leaving' => 'Completed Primary Education',
            'special_information' => 'Mild asthma.',
        ], $application);
    }
}

// ---------------------------------------------------------------- step gate

it('refuses to create a draft when a required step 1 field is missing', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    expect(fn () => app(SaveApplicationStep::class)->handle(
        WizardStep::BasicInformation,
        admissionsStepOne(['last_name' => '']),
    ))->toThrow(ValidationException::class);

    // The gate is what stops progression; a half-typed form must leave nothing
    // behind, or the Drafts tab fills with rows nobody meant to start.
    expect(AdmissionApplication::query()->count())->toBe(0);
});

it('refuses a date of birth in the future', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    expect(fn () => app(SaveApplicationStep::class)->handle(
        WizardStep::BasicInformation,
        admissionsStepOne(['date_of_birth' => Carbon::tomorrow()->toDateString()]),
    ))->toThrow(ValidationException::class);
});

it('requires exactly one primary guardian at step 3', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();
    $save = app(SaveApplicationStep::class);

    $application = $save->handle(WizardStep::BasicInformation, admissionsStepOne());

    expect(fn () => $save->handle(WizardStep::ParentGuardian, [
        'guardians' => [
            admissionsGuardianRow(),
            admissionsGuardianRow(['first_name' => 'Andre', 'gender' => 'male', 'relationship' => 'father']),
        ],
    ], $application))->toThrow(ValidationException::class);

    expect(DB::table('admission_application_guardians')->count())->toBe(0);

    // One primary, and the second guardian merely present, is accepted.
    $save->handle(WizardStep::ParentGuardian, [
        'guardians' => [
            admissionsGuardianRow(),
            admissionsGuardianRow([
                'first_name' => 'Andre', 'gender' => 'male', 'relationship' => 'father',
                'is_primary' => false, 'has_custody' => false, 'phone' => '677445566',
            ]),
        ],
    ], $application);

    expect(DB::table('admission_application_guardians')->count())->toBe(2);
    expect($fixture['year'])->toBeInt();
});

it('rejects a primary guardian who does not hold custody', function () {
    // 7.2 states the implication and says it is rejected, not coerced.
    actingAs(admissionsUserAs(Role::Registrar));
    $save = app(SaveApplicationStep::class);

    $application = $save->handle(WizardStep::BasicInformation, admissionsStepOne());

    expect(fn () => $save->handle(WizardStep::ParentGuardian, [
        'guardians' => [admissionsGuardianRow(['has_custody' => false])],
    ], $application))->toThrow(ValidationException::class);
});

// ------------------------------------------------------------------- drafts

it('persists the draft between steps and remembers how far it got', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();
    $save = app(SaveApplicationStep::class);

    $application = $save->handle(WizardStep::BasicInformation, admissionsStepOne());

    assertDatabaseHas('admission_applications', [
        'id' => $application->getKey(),
        'status' => ApplicationStatus::Draft->value,
        'completed_step' => WizardStep::BasicInformation->value,
        'application_no' => null,
    ]);

    $application = $save->handle(WizardStep::AcademicDetails, [
        'academic_year_id' => $fixture['year'],
        'class_level_id' => $fixture['level'],
        'admission_date' => '2026-09-05',
    ], $application);

    // The step 1 columns survive the step 2 save; a step must never blank a
    // column it does not own.
    $fresh = admissionsReload((int) $application->getKey());

    expect($fresh->first_name)->toBe('Ncham')
        ->and($fresh->completed_step)->toBe(WizardStep::AcademicDetails->value)
        ->and($fresh->class_level_id)->toBe($fixture['level']);

    // Walking Back to step 1 and re-saving must not un-complete step 2.
    $save->handle(WizardStep::BasicInformation, admissionsStepOne(['middle_name' => 'Andre Paul']), $fresh);

    expect(admissionsReload((int) $application->getKey())->completed_step)
        ->toBe(WizardStep::AcademicDetails->value);
});

it('encrypts the identity and health fields at rest', function () {
    // 6.1 requires the same encryption as Student 3.1, and calls
    // special_information out by name as health data about a minor.
    actingAs(admissionsUserAs(Role::Registrar));

    $application = app(SaveApplicationStep::class)
        ->handle(WizardStep::BasicInformation, admissionsStepOne());

    $stored = DB::table('admission_applications')
        ->where('id', '=', $application->getKey())
        ->value('genotype');

    expect($stored)->toBeString()
        ->and($stored)->not->toBe('AA');

    // ...and the cast reads it back, so the protection is transparent to
    // everything except a stolen .sql file.
    expect(admissionsReload((int) $application->getKey())->genotype)->toBe('AA');
});

// ------------------------------------------------------------------- submit

it('allocates the application number only at submit and refuses a second submit', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $application = admissionsCompleteDraft($fixture);

    // 6.2: peek shows the next number without consuming it.
    $preview = app(SubmitApplication::class)->previewNumber($application);
    expect($preview)->toBe('APP/2026-2027/0001');
    expect(app(SubmitApplication::class)->previewNumber($application))->toBe($preview);

    $submitted = app(SubmitApplication::class)->handle($application);

    expect($submitted->application_no)->toBe('APP/2026-2027/0001')
        ->and($submitted->status)->toBe(ApplicationStatus::Submitted);

    expect(fn () => app(SubmitApplication::class)->handle($submitted))
        ->toThrow(ValidationException::class);
});

it('refuses to submit a draft that skipped a step', function () {
    actingAs(admissionsUserAs(Role::Registrar));

    $application = app(SaveApplicationStep::class)
        ->handle(WizardStep::BasicInformation, admissionsStepOne());

    expect(fn () => app(SubmitApplication::class)->handle($application))
        ->toThrow(ValidationException::class);
});

// ------------------------------------------------------------------ convert

it('converts a submitted application into a student, an enrolment and a guardian link', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $application = admissionsCompleteDraft($fixture);
    $submitted = app(SubmitApplication::class)->handle($application);

    $result = app(ConvertApplication::class)->handle($submitted, $fixture['group']);

    // The student exists, carries a TEMPORARY matricule (6.4) and an
    // admission number, and is not the application's own number.
    assertDatabaseHas('students', [
        'id' => $result['student_id'],
        'first_name' => 'Ncham',
        'last_name' => 'Bela',
        'matricule_is_official' => false,
    ]);

    // The enrolment exists for the applied-for year, and - because a previous
    // school was named (6.6) - is a transfer_in rather than a new admission.
    assertDatabaseHas('enrollments', [
        'id' => $result['enrollment_id'],
        'student_id' => $result['student_id'],
        'academic_year_id' => $fixture['year'],
        'enrollment_type' => 'transfer_in',
    ]);

    // The initial segment: an enrolment with no segment is invisible to
    // attendance (5.2), so its absence would be a silent defect.
    assertDatabaseHas('enrollment_segments', [
        'enrollment_id' => $result['enrollment_id'],
        'class_group_id' => $fixture['group'],
    ]);

    expect($result['guardian_ids'])->toHaveCount(1);

    assertDatabaseHas('student_guardians', [
        'student_id' => $result['student_id'],
        'guardian_id' => $result['guardian_ids'][0],
        'is_primary' => true,
        'has_custody' => true,
        // 6.3 step 5: valid_from is the enrolment date, not the day the form
        // was typed.
        'valid_from' => '2026-09-05',
    ]);

    assertDatabaseHas('admission_applications', [
        'id' => $submitted->getKey(),
        'status' => ApplicationStatus::Enrolled->value,
        'converted_student_id' => $result['student_id'],
        'purge_due_on' => null,
    ]);

    // 00-core 14: every one of those writes names an actor and lands on the
    // hash chain. Asserting the rows without asserting the audit would let a
    // silent, unattributable conversion pass.
    assertDatabaseHas('audit_logs', [
        'module' => 'Admissions',
        'auditable_type' => AdmissionApplication::class,
        'auditable_id' => $submitted->getKey(),
        'action' => 'updated',
    ]);
    assertDatabaseHas('audit_logs', ['module' => 'Students', 'action' => 'created']);
    assertDatabaseHas('audit_logs', ['module' => 'Guardians', 'action' => 'created']);

    expect(DB::table('audit_logs')->where('actor_name_at_time', 'Admissions Clerk')->count())
        ->toBeGreaterThan(0);
});

it('refuses a second conversion of the same application', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));
    app(ConvertApplication::class)->handle($submitted, $fixture['group']);

    expect(fn () => app(ConvertApplication::class)->handle(
        admissionsReload((int) $submitted->getKey()),
        $fixture['group'],
    ))->toThrow(DomainException::class);

    // Exactly one student, which is the outcome converted_student_id's UNIQUE
    // index exists to guarantee.
    expect(DB::table('students')->count())->toBe(1);
    expect(DB::table('enrollments')->count())->toBe(1);
});

it('refuses to convert a draft that was never submitted', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $draft = admissionsCompleteDraft($fixture);

    expect(fn () => app(ConvertApplication::class)->handle($draft, $fixture['group']))
        ->toThrow(ValidationException::class);

    assertDatabaseMissing('students', ['first_name' => 'Ncham']);
});

it('reuses an existing guardian matched on the national ID rather than duplicating them', function () {
    // 7.7 tier 1. The blind index is UNIQUE, so a second record on the same ID
    // is impossible; the sibling of an existing pupil must link, not collide.
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();
    $save = app(SaveApplicationStep::class);

    $existing = app(\App\Modules\Guardians\Actions\CreateGuardian::class)->handle([
        'first_name' => 'Merceline',
        'last_name' => 'Bela',
        'gender' => 'female',
        'phone' => '699000111',
        'id_type' => 'national_id',
        'id_number' => 'CM-ID-4821',
    ]);

    $application = $save->handle(WizardStep::BasicInformation, admissionsStepOne());
    $application = $save->handle(WizardStep::AcademicDetails, [
        'academic_year_id' => $fixture['year'],
        'class_level_id' => $fixture['level'],
        'admission_date' => '2026-09-05',
    ], $application);
    $application = $save->handle(WizardStep::ParentGuardian, [
        'guardians' => [admissionsGuardianRow(['id_type' => 'national_id', 'id_number' => 'CM-ID-4821'])],
    ], $application);
    $application = $save->handle(WizardStep::OtherInformation, [], $application);

    $result = app(ConvertApplication::class)->handle(
        app(SubmitApplication::class)->handle($application),
        $fixture['group'],
    );

    expect($result['guardian_ids'][0])->toBe((int) $existing['guardian']->getKey());
    expect(DB::table('guardians')->count())->toBe(1);
});

// ------------------------------------------------------------------- reject

it('retains a rejected application for twelve months instead of deleting it', function () {
    // 6.5. The row survives; only the purge job (later phase) pseudonymises
    // it, and it may not do so before purge_due_on.
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));

    $rejected = app(RejectApplication::class)->handle($submitted, 'Class is full for this session.');

    expect($rejected->status)->toBe(ApplicationStatus::Rejected)
        ->and($rejected->decision_reason)->toBe('Class is full for this session.')
        ->and($rejected->purge_due_on?->toDateString())
        ->toBe($rejected->decided_at?->copy()->addMonths(12)->toDateString());

    // The statistics skeleton 6.5 preserves: number, class applied for, the
    // decision and its date all still on the row.
    assertDatabaseHas('admission_applications', [
        'id' => $submitted->getKey(),
        'application_no' => $submitted->application_no,
        'class_level_id' => $fixture['level'],
        'status' => ApplicationStatus::Rejected->value,
    ]);

    assertDatabaseHas('audit_logs', [
        'module' => 'Admissions',
        'auditable_id' => $submitted->getKey(),
        'action' => 'updated',
    ]);
});

it('demands a reason before rejecting', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));

    expect(fn () => app(RejectApplication::class)->handle($submitted, '   '))
        ->toThrow(ValidationException::class);

    expect(admissionsReload((int) $submitted->getKey())->status)
        ->toBe(ApplicationStatus::Submitted);
});

it('refuses to reject an application that has already been converted', function () {
    actingAs(admissionsUserAs(Role::Registrar));
    $fixture = admissionsFixture();

    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));
    app(ConvertApplication::class)->handle($submitted, $fixture['group']);

    expect(fn () => app(RejectApplication::class)->handle(
        admissionsReload((int) $submitted->getKey()),
        'Changed our mind.',
    ))->toThrow(ValidationException::class);
});

// -------------------------------------------------------------- permissions

it('denies every admissions Action to a role without admissions.manage', function () {
    actingAs(admissionsUserAs(Role::Bursar));

    expect(fn () => app(SaveApplicationStep::class)
        ->handle(WizardStep::BasicInformation, admissionsStepOne()))
        ->toThrow(AuthorizationException::class);
});

it('denies submit, reject and convert to a role without admissions.manage', function () {
    $fixture = admissionsFixture();

    actingAs(admissionsUserAs(Role::Registrar));
    $submitted = app(SubmitApplication::class)->handle(admissionsCompleteDraft($fixture));

    actingAs(admissionsUserAs(Role::Teacher));

    expect(fn () => app(SubmitApplication::class)->handle($submitted))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(RejectApplication::class)->handle($submitted, 'No.'))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ConvertApplication::class)->handle($submitted, $fixture['group']))
        ->toThrow(AuthorizationException::class);
});
