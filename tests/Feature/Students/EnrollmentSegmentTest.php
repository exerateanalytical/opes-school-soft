<?php

declare(strict_types=1);

use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\User;
use App\Modules\Students\Actions\EnrollStudent;
use App\Modules\Students\Actions\TransferStudentClass;
use App\Modules\Students\Actions\WithdrawStudent;
use App\Modules\Students\Domain\SegmentReason;
use App\Modules\Students\Models\Enrollment;
use App\Modules\Students\Models\EnrollmentSegment;
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
 * The same guarded local helpers as EnrollmentTest.php. Duplicated rather than
 * required, because requiring a Pest file would re-register its tests; the
 * function_exists guards mean whichever file Pest loads first wins and the two
 * suites run alone or together.
 *
 * Prerequisite rows go in through DB::table() rather than the Student /
 * AcademicYear / ClassGroup factories: those belong to other workstreams and
 * this suite must not depend on their code to stay green.
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


/**
 * @return array{enrollment: Enrollment, year: int, level: int, groupA: int, groupB: int}
 */
function segmentFixture(int $capacity = 60): array
{
    $yearId = enrollmentYear();
    $levelId = enrollmentLevel();
    $groupA = enrollmentGroup($yearId, $levelId, 'Form 2A', $capacity);
    $groupB = enrollmentGroup($yearId, $levelId, 'Form 2B', $capacity);

    $enrollment = app(EnrollStudent::class)
        ->handle(enrollmentStudent(), $yearId, $groupA, '2026-09-05');

    return [
        'enrollment' => $enrollment,
        'year' => $yearId,
        'level' => $levelId,
        'groupA' => $groupA,
        'groupB' => $groupB,
    ];
}

// ---------------------------------------------------------------------------
// C2 - contiguity (07-students 5.2)
// ---------------------------------------------------------------------------

it('produces adjacent non-overlapping segments on a mid-year transfer', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $enrollment = $fixture['enrollment'];

    // 5's worked example: Form 2A -> Form 2B on 12 November.
    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $fixture['groupB'], '2026-11-12');

    $segments = EnrollmentSegment::query()
        ->where('enrollment_id', $enrollment->getKey())
        ->orderBy('starts_on')
        ->get()
        ->all();

    expect($segments)->toHaveCount(2);

    [$first, $second] = $segments;

    expect($first->class_group_id)->toBe($fixture['groupA']);
    expect($first->ends_on?->toDateString())->toBe('2026-11-11');
    expect($second->class_group_id)->toBe($fixture['groupB']);
    expect($second->starts_on->toDateString())->toBe('2026-11-12');
    expect($second->ends_on)->toBeNull();

    // The invariant, stated as arithmetic: the successor starts exactly one
    // day after its predecessor ends. Strict equality catches both failure
    // modes at once - a gap (> 1 day) and an overlap (<= 0 days).
    expect($first->ends_on?->addDay()->toDateString())
        ->toBe($second->starts_on->toDateString());

    // And still exactly ONE Enrollment. This is the whole of C2: every mark,
    // invoice and attendance row keyed on enrollment_id survives the transfer.
    expect(Enrollment::query()->where('student_id', $enrollment->student_id)->count())->toBe(1);
});

it('makes a second open segment impossible at the database level', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $enrollment = $fixture['enrollment'];

    // uq_segment_open keys on the `open_key` generated column, which carries
    // enrollment_id only while ends_on IS NULL. An overlap can therefore not
    // be inserted even by code that bypasses TransferStudentClass entirely.
    expect(fn () => DB::table('enrollment_segments')->insert([
        'enrollment_id' => $enrollment->getKey(),
        'class_group_id' => $fixture['groupB'],
        'starts_on' => '2026-10-01',
        'ends_on' => null,
        'reason' => 'class_transfer',
        'capacity_override' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    expect(EnrollmentSegment::query()
        ->where('enrollment_id', $enrollment->getKey())
        ->whereNull('ends_on')
        ->count())->toBe(1);
});

it('rejects a segment that ends before it starts', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    // chk_enrollment_segments_range. A zero-or-negative-length segment would
    // make 5.3's "the segment covering P.ends_on" ambiguous.
    expect(fn () => DB::table('enrollment_segments')->insert([
        'enrollment_id' => $fixture['enrollment']->getKey(),
        'class_group_id' => $fixture['groupB'],
        'starts_on' => '2026-11-12',
        'ends_on' => '2026-11-01',
        'reason' => 'class_transfer',
        'capacity_override' => false,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('keeps three consecutive transfers contiguous end to end', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $enrollment = $fixture['enrollment'];
    $groupC = enrollmentGroup($fixture['year'], $fixture['level'], 'Form 2C');

    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $fixture['groupB'], '2026-11-12');
    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $groupC, '2027-02-03');
    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $fixture['groupA'], '2027-05-04');

    $segments = EnrollmentSegment::query()
        ->where('enrollment_id', $enrollment->getKey())
        ->orderBy('starts_on')
        ->get()
        ->all();

    expect($segments)->toHaveCount(4);

    // 5.2: the union covers [enrolled_on, left_on ?? year end] with no gaps.
    expect($segments[0]->starts_on->toDateString())->toBe('2026-09-05');

    for ($i = 1, $n = count($segments); $i < $n; $i++) {
        $previous = $segments[$i - 1];
        $current = $segments[$i];

        expect($previous->ends_on?->addDay()->toDateString())
            ->toBe($current->starts_on->toDateString());
    }

    expect($segments[count($segments) - 1]->ends_on)->toBeNull();
});

it('refuses a transfer effective on or before the current segment start', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    // Closing on effective-1 would give ends_on < starts_on. Rejected here
    // with a message rather than by the CHECK with a driver exception.
    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $fixture['groupB'], '2026-09-05'))
        ->toThrow(ValidationException::class);
});

// ---------------------------------------------------------------------------
// 5.3 - which class group owns rank and statistics
// ---------------------------------------------------------------------------

it('resolves the owning class group from the segment covering a date', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $enrollment = $fixture['enrollment'];

    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $fixture['groupB'], '2026-11-12');

    $enrollment->refresh();

    // 5.3: "an enrollment's owning class group is the class group of the
    // segment covering P.ends_on". Term 1 ends 15 Dec, so the student ranks
    // in 2B - once, with ALL their Term 1 marks, including those earned in 2A.
    expect($enrollment->segmentCovering('2026-12-15')?->class_group_id)->toBe($fixture['groupB']);

    // Term 1 mid-October still resolves to 2A, which is what gives the report
    // card its "Transferre de Form 2A" provenance line.
    expect($enrollment->segmentCovering('2026-10-20')?->class_group_id)->toBe($fixture['groupA']);

    // Every day of the enrollment resolves to exactly one group - the
    // no-gap half of the invariant, checked as a consumer would feel it.
    foreach (['2026-09-05', '2026-11-11', '2026-11-12', '2027-06-30'] as $date) {
        expect($enrollment->segmentCovering($date))->not->toBeNull();
    }
});

// ---------------------------------------------------------------------------
// 5.2 target validation
// ---------------------------------------------------------------------------

it('refuses a transfer into another academic year', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $otherYear = enrollmentYear('2027-2028', '2027-09-01', '2028-07-31', isCurrent: false);
    $otherGroup = enrollmentGroup($otherYear, $fixture['level'], 'Form 2A');

    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $otherGroup, '2026-11-12'))
        ->toThrow(ValidationException::class);
});

it('refuses a transfer to a different class level', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $otherLevel = enrollmentLevel('F3');
    $otherGroup = enrollmentGroup($fixture['year'], $otherLevel, 'Form 3A');

    // 4.1 freezes class_level_id for the year; a level change is a promotion
    // decision (10), not a segment edit.
    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $otherGroup, '2026-11-12'))
        ->toThrow(ValidationException::class);
});

it('refuses a transfer into a full class group without an override', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture(capacity: 1);

    // groupB already holds its one permitted student.
    app(EnrollStudent::class)
        ->handle(enrollmentStudent('Tabi'), $fixture['year'], $fixture['groupB'], '2026-09-05');

    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $fixture['groupB'], '2026-11-12'))
        ->toThrow(ValidationException::class);
});

it('refuses a transfer to the group the student is already in', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $fixture['groupA'], '2026-11-12'))
        ->toThrow(ValidationException::class);
});

it('refuses a transfer on a terminated enrollment', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    app(WithdrawStudent::class)
        ->handle((int) $fixture['enrollment']->getKey(), '2026-10-31', 'Relocated');

    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $fixture['groupB'], '2026-11-12'))
        ->toThrow(ValidationException::class);
});

it('refuses a second initial segment', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    expect(fn () => app(TransferStudentClass::class)->handle(
        (int) $fixture['enrollment']->getKey(),
        $fixture['groupB'],
        '2026-11-12',
        SegmentReason::Initial,
    ))->toThrow(ValidationException::class);
});

it('requires reason text for a correction', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    expect(fn () => app(TransferStudentClass::class)->handle(
        (int) $fixture['enrollment']->getKey(),
        $fixture['groupB'],
        '2026-11-12',
        SegmentReason::Correction,
    ))->toThrow(ValidationException::class);

    $segment = app(TransferStudentClass::class)->handle(
        (int) $fixture['enrollment']->getKey(),
        $fixture['groupB'],
        '2026-11-12',
        SegmentReason::Correction,
        'Data entry error at enrolment.',
    );

    expect($segment->reason)->toBe(SegmentReason::Correction);
});

// ---------------------------------------------------------------------------
// Authorisation, audit and the activity feed
// ---------------------------------------------------------------------------

it('rejects a transfer by a user without students.manage', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));
    $fixture = segmentFixture();

    actingAs(enrollmentUserAs(Role::Teacher));

    expect(fn () => app(TransferStudentClass::class)
        ->handle((int) $fixture['enrollment']->getKey(), $fixture['groupB'], '2026-11-12'))
        ->toThrow(AuthorizationException::class);

    expect(EnrollmentSegment::query()
        ->where('enrollment_id', $fixture['enrollment']->getKey())
        ->count())->toBe(1);
});

it('audits the transfer and logs it to the student activity feed', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();
    $enrollment = $fixture['enrollment'];

    app(TransferStudentClass::class)
        ->handle((int) $enrollment->getKey(), $fixture['groupB'], '2026-11-12');

    assertDatabaseHas('audit_logs', [
        'action' => 'updated',
        'module' => 'Students',
        'auditable_type' => Enrollment::class,
        'auditable_id' => $enrollment->getKey(),
    ]);

    assertDatabaseHas('student_activity_logs', [
        'student_id' => $enrollment->student_id,
        'enrollment_id' => $enrollment->getKey(),
        'event' => 'class_transferred',
    ]);
});

it('records a stream change as its own activity event', function (): void {
    actingAs(enrollmentUserAs(Role::Registrar));

    $fixture = segmentFixture();

    app(TransferStudentClass::class)->handle(
        (int) $fixture['enrollment']->getKey(),
        $fixture['groupB'],
        '2026-11-12',
        SegmentReason::StreamChange,
    );

    assertDatabaseHas('student_activity_logs', [
        'student_id' => $fixture['enrollment']->student_id,
        'event' => 'stream_changed',
    ]);
});
