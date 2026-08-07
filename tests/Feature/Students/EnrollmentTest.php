<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\DeriveStudentStatus;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Actions\WithdrawStudent;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Domain\StudentStatus;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\assertDatabaseHas;

uses(RefreshDatabase::class);

/**
 * Prerequisite rows are inserted with DB::table() rather than through the
 * Student / AcademicYear / ClassGroup factories: those belong to other
 * workstreams and this suite must not depend on their code to stay green.
 */
if (! function_exists('enrollmentUserAs')) {
    function enrollmentUserAs(Role $role): User
    {
        (new \Database\Seeders\RolePermissionSeeder)->run();
        $user = User::factory()->create(['name' => 'Enrolment Officer']);
        $user->assignRole($role->value);

        return $user->fresh() ?? $user;
    }
}

if (! function_exists('enrollmentYear')) {
    function enrollmentYear(
        string $code = '2026-2027',
        string $startsOn = '2026-09-01',
        string $endsOn = '2027-07-31',
        bool $isCurrent = true,
    ): int {
        return (int) DB::table('academic_years')->insertGetId([
            'code' => $code,
            'name' => "Academic Year {$code}",
            'starts_on' => $startsOn,
            'ends_on' => $endsOn,
            'is_current' => $isCurrent,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('enrollmentSection')) {
    function enrollmentSection(): int
    {
        $existing = DB::table('school_sections')->value('id');

        if (is_numeric($existing)) {
            return (int) $existing;
        }

        return (int) DB::table('school_sections')->insertGetId([
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
}

if (! function_exists('enrollmentLevel')) {
    function enrollmentLevel(string $code = 'F1', bool $isExamClass = false): int
    {
        return (int) DB::table('class_levels')->insertGetId([
            'school_section_id' => enrollmentSection(),
            'code' => $code,
            'name' => "Form {$code}",
            'name_fr' => "Niveau {$code}",
            'order_index' => 1,
            'is_exam_class' => $isExamClass,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('enrollmentGroup')) {
    function enrollmentGroup(int $yearId, int $levelId, string $name = 'Form 1A', int $capacity = 60): int
    {
        return (int) DB::table('class_groups')->insertGetId([
            'class_level_id' => $levelId,
            'academic_year_id' => $yearId,
            'name' => $name,
            'capacity' => $capacity,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

if (! function_exists('enrollmentStudent')) {
    function enrollmentStudent(string $lastName = 'Nkeng'): int
    {
        $suffix = Str::upper(Str::random(8));

        return (int) DB::table('students')->insertGetId([
            'matricule' => 'OS-26-'.$suffix,
            'matricule_is_official' => true,
            'admission_no' => 'HA/ADM/2026/'.$suffix,
            'first_name' => 'Ayuk',
            'last_name' => $lastName,
            'date_of_birth' => '2012-04-11',
            'place_of_birth' => 'Bamenda',
            'gender' => 'male',
            'nationality' => 'CM',
            'status' => 'prospective',
            'is_archived' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

// ---------------------------------------------------------------------------
// C1 - 07-students 4.3
// ---------------------------------------------------------------------------

it('creates an enrollment and its initial open segment together', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');

    expect($enrollment->status)->toBe(EnrollmentStatus::Active);
    // 4.1: the section is denormalised from the level and frozen here.
    expect($enrollment->school_section_id)->toBe(enrollmentSection());

    // 5.2: first segment starts on enrolled_on, reason `initial`, still open.
    assertDatabaseHas('enrollment_segments', [
        'enrollment_id' => $enrollment->getKey(),
        'class_group_id' => $groupId,
        'starts_on' => '2026-09-05',
        'ends_on' => null,
        'reason' => 'initial',
    ]);
});

it('rejects a second live enrollment for the same student and year (C1)', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupA = enrollmentGroup($yearId, $levelId, 'Form 1A');
    $groupB = enrollmentGroup($yearId, $levelId, 'Form 1B');
    $studentId = enrollmentStudent();

    app(EnrollStudent::class)->handle($studentId, $yearId, $groupA, '2026-09-05');

    // The spec's acceptance criterion: the loser sees "already enrolled for
    // 2026/2027", not a 500.
    expect(fn () => app(EnrollStudent::class)->handle($studentId, $yearId, $groupB, '2026-09-06'))
        ->toThrow(ValidationException::class);

    expect(Enrollment::query()->where('student_id', $studentId)->count())->toBe(1);
});

it('still rejects a re-enrollment in the SAME year after a withdrawal', function (): void {
    // 4.3 is explicit and this is the surprising half of it:
    // "uq_enrollment_student_year is the stronger of the two and is the one
    //  that matters: one Enrollment per student per year, full stop, INCLUDING
    //  terminal ones."
    // Withdrawing frees active_year_key but NOT the plain composite. A
    // re-admission inside the same year is a status change on the existing
    // row, never a second row.
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    app(WithdrawStudent::class)->handle((int) $enrollment->getKey(), '2026-11-30', 'Relocated');

    expect(fn () => app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-12-01'))
        ->toThrow(ValidationException::class);

    expect(Enrollment::query()->where('student_id', $studentId)->count())->toBe(1);
});

it('allows a fresh enrollment in a DIFFERENT year after a withdrawal', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearOne = enrollmentYear('2026-2027', '2026-09-01', '2027-07-31', isCurrent: false);
    $yearTwo = enrollmentYear('2027-2028', '2027-09-01', '2028-07-31');
    $levelId = enrollmentLevel();
    $groupOne = enrollmentGroup($yearOne, $levelId, 'Form 1A');
    $groupTwo = enrollmentGroup($yearTwo, $levelId, 'Form 1A');
    $studentId = enrollmentStudent();

    $first = app(EnrollStudent::class)->handle($studentId, $yearOne, $groupOne, '2026-09-05');
    app(WithdrawStudent::class)->handle((int) $first->getKey(), '2027-01-20', 'Family moved');

    $second = app(EnrollStudent::class)->handle($studentId, $yearTwo, $groupTwo, '2027-09-04');

    expect($second->status)->toBe(EnrollmentStatus::Active);
    expect(Enrollment::query()->where('student_id', $studentId)->count())->toBe(2);
});

it('lets the database, not the Action, be the last word on C1', function (): void {
    // Bypassing EnrollStudent entirely: the guarantee has to survive an
    // importer or a console command that forgets to check first.
    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $studentId = enrollmentStudent();

    $row = [
        'student_id' => $studentId,
        'academic_year_id' => $yearId,
        'class_level_id' => $levelId,
        'school_section_id' => enrollmentSection(),
        'status' => 'active',
        'is_repeat' => false,
        'enrollment_type' => 'new',
        'enrolled_on' => '2026-09-05',
        'boarding_status' => 'day',
        'financial_clearance' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    DB::table('enrollments')->insert($row);

    expect(fn () => DB::table('enrollments')->insert($row))->toThrow(QueryException::class);
});

it('refuses an enrolment date outside the academic year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    // 4.2 invariant 1 - not expressible as a MySQL CHECK across tables.
    expect(fn () => app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-08-15'))
        ->toThrow(ValidationException::class);
});

it('refuses to enrol into a full class group without an override', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId, 'Form 1A', capacity: 1);

    app(EnrollStudent::class)->handle(enrollmentStudent('One'), $yearId, $groupId, '2026-09-05');

    expect(fn () => app(EnrollStudent::class)
        ->handle(enrollmentStudent('Two'), $yearId, $groupId, '2026-09-05'))
        ->toThrow(ValidationException::class);

    // 4.2 invariant 7: over-capacity is permitted, but only explicitly.
    $overridden = app(EnrollStudent::class)->handle(
        enrollmentStudent('Three'), $yearId, $groupId, '2026-09-05', capacityOverride: true,
    );

    expect($overridden->exists)->toBeTrue();
});

// ---------------------------------------------------------------------------
// Authorisation
// ---------------------------------------------------------------------------

it('rejects enrolment by a user without students.manage', function (): void {
    actingAs(enrollmentUserAs(Role::Teacher));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    expect(fn () => app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05'))
        ->toThrow(AuthorizationException::class);

    expect(Enrollment::query()->count())->toBe(0);
});

it('rejects withdrawal by a user without students.manage', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupId, '2026-09-05');

    actingAs(enrollmentUserAs(Role::Teacher));

    expect(fn () => app(WithdrawStudent::class)
        ->handle((int) $enrollment->getKey(), '2026-11-30', 'Relocated'))
        ->toThrow(AuthorizationException::class);
});

// ---------------------------------------------------------------------------
// 3.3 transition graph and 4.2 invariant 3
// ---------------------------------------------------------------------------

it('closes the open segment on withdrawal and ties left_on to the terminal status', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupId, '2026-09-05');

    app(WithdrawStudent::class)->handle((int) $enrollment->getKey(), '2026-11-30', 'Relocated');

    $fresh = $enrollment->refresh();
    expect($fresh->status)->toBe(EnrollmentStatus::Withdrawn);
    expect($fresh->left_on?->toDateString())->toBe('2026-11-30');

    // 5.2: on a terminal status the open segment's ends_on becomes left_on,
    // in the SAME transaction. No open segment survives a withdrawal.
    assertDatabaseHas('enrollment_segments', [
        'enrollment_id' => $enrollment->getKey(),
        'ends_on' => '2026-11-30',
    ]);

    expect(DB::table('enrollment_segments')
        ->where('enrollment_id', $enrollment->getKey())
        ->whereNull('ends_on')
        ->count())->toBe(0);
});

it('refuses to withdraw an already withdrawn enrollment', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupId, '2026-09-05');

    app(WithdrawStudent::class)->handle((int) $enrollment->getKey(), '2026-11-30', 'Relocated');

    // 3.3: terminal is terminal. Without this the second call would rewrite
    // left_on and every date-keyed report would silently change.
    expect(fn () => app(WithdrawStudent::class)
        ->handle((int) $enrollment->getKey(), '2027-01-10', 'Again'))
        ->toThrow(ValidationException::class);
});

it('refuses a leaving date before the enrolment date', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupId, '2026-09-05');

    expect(fn () => app(WithdrawStudent::class)
        ->handle((int) $enrollment->getKey(), '2026-09-01', 'Typo'))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 3.2 - the derivation rule, row by row
// ---------------------------------------------------------------------------

it('derives prospective for a student with no enrollment at all', function (): void {
    enrollmentYear();
    $studentId = enrollmentStudent();

    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Prospective);
});

it('derives active from a live enrollment in the current year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');

    // EnrollStudent persists the derivation itself - the cache is never left
    // for a nightly job to notice.
    expect(DB::table('students')->where('id', $studentId)->value('status'))->toBe('active');
});

it('derives active for a suspended enrollment in the current year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    DB::table('enrollments')->where('id', $enrollment->getKey())->update(['status' => 'suspended']);

    // Row 2 of the 3.2 table treats suspended as still enrolled: a suspended
    // student is still on the roll, still invoiced, still counted.
    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Active);
});

it('derives prospective from a pending enrollment in the current year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    DB::table('enrollments')->where('id', $enrollment->getKey())->update(['status' => 'pending']);

    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Prospective);
});

it('derives withdrawn from the latest terminal enrollment', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    app(WithdrawStudent::class)->handle((int) $enrollment->getKey(), '2026-11-30', 'Relocated');

    expect(DB::table('students')->where('id', $studentId)->value('status'))->toBe('withdrawn');
});

it('derives transferred_out from the latest terminal enrollment', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    app(WithdrawStudent::class)->handle(
        (int) $enrollment->getKey(), '2026-12-15', 'Moved school', EnrollmentStatus::TransferredOut,
    );

    expect(DB::table('students')->where('id', $studentId)->value('status'))->toBe('transferred_out');
});

it('derives graduated only when the completed enrollment sat an exit level', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear('2026-2027', '2026-09-01', '2027-07-31', isCurrent: false);
    $exitLevel = enrollmentLevel('U6', isExamClass: true);
    $groupId = enrollmentGroup($yearId, $exitLevel);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    DB::table('enrollments')->where('id', $enrollment->getKey())
        ->update(['status' => 'completed', 'left_on' => '2027-07-31']);

    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Graduated);

    // The same completion at a non-exit level is a year finished, not a
    // school finished - it falls through to row 7.
    $otherStudent = enrollmentStudent('Mbah');
    $midLevel = enrollmentLevel('F3');
    $midGroup = enrollmentGroup($yearId, $midLevel, 'Form 3A');
    $other = app(EnrollStudent::class)->handle($otherStudent, $yearId, $midGroup, '2026-09-05');
    DB::table('enrollments')->where('id', $other->getKey())
        ->update(['status' => 'completed', 'left_on' => '2027-07-31']);

    expect(app(DeriveStudentStatus::class)->derive($otherStudent))->toBe(StudentStatus::Inactive);
});

it('derives inactive when history exists but nothing in the current year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $pastYear = enrollmentYear('2025-2026', '2025-09-01', '2026-07-31', isCurrent: false);
    enrollmentYear('2026-2027', '2026-09-01', '2027-07-31');
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($pastYear, $levelId);
    $studentId = enrollmentStudent();

    $enrollment = app(EnrollStudent::class)->handle($studentId, $pastYear, $groupId, '2025-09-05');
    DB::table('enrollments')->where('id', $enrollment->getKey())
        ->update(['status' => 'completed', 'left_on' => '2026-07-31']);

    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Inactive);
});

it('derives deceased ahead of every other row', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');
    DB::table('students')->where('id', $studentId)->update(['deceased_on' => '2026-10-02']);

    // Row 1 wins even though a live current-year enrollment would otherwise
    // say `active`. Order in the 3.2 table is the specification.
    expect(app(DeriveStudentStatus::class)->derive($studentId))->toBe(StudentStatus::Deceased);
});

it('persists the derivation and records a status transition', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $studentId = enrollmentStudent();

    app(EnrollStudent::class)->handle($studentId, $yearId, $groupId, '2026-09-05');

    // 3.3: append-only, and written in the same transaction as the change.
    assertDatabaseHas('student_status_transitions', [
        'student_id' => $studentId,
        'from_status' => 'prospective',
        'to_status' => 'active',
    ]);
});

it('audits the enrollment it creates', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupId = enrollmentGroup($yearId, $levelId);
    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupId, '2026-09-05');

    assertDatabaseHas('audit_logs', [
        'action' => 'created',
        'module' => 'Students',
        'auditable_type' => Enrollment::class,
        'auditable_id' => $enrollment->getKey(),
        'actor_name_at_time' => 'Enrolment Officer',
    ]);
});
