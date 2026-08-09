<?php

declare(strict_types=1);

use App\Modules\Library\Actions\EnrollLibraryMember;
use App\Modules\Library\Domain\LibraryPermission;
use App\Modules\Library\Domain\MemberType;
use App\Modules\Library\Models\LibraryMember;
use App\Modules\Students\Models\Enrollment;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/LibraryTestHelpers.php';

uses(RefreshDatabase::class);

it('enrolls a student through the door and DERIVES student_id from the enrollment (§10.3 invariant)', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $enrollment = phase9LibEnrollment($calendar['academic_year_id']);

    // The caller LIES about the student; the Action must derive, never accept.
    $member = phase9LibStudentMember($user, $calendar['academic_year_id'], $class, $enrollment, [
        'student_id' => 999_999,
    ]);

    expect($member->member_no)->toMatch('#^LM/2031/\d{5}$#')
        ->and($member->member_type)->toBe(MemberType::Student)
        ->and($member->student_id)->toBe((int) $enrollment->student_id)
        ->and($member->enrollment_id)->toBe((int) $enrollment->getKey())
        ->and($member->staff_member_id)->toBeNull();
});

it('refuses a student membership without an enrollment, on a non-active enrollment, and on a year mismatch', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();

    // No enrollment at all.
    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'student',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'enrollment_id is required');

    // A withdrawn enrollment never joins.
    $withdrawn = Enrollment::factory()->withdrawn('2031-01-05')->create([
        'academic_year_id' => $calendar['academic_year_id'],
    ]);

    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'student',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'enrollment_id' => (int) $withdrawn->getKey(),
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'only an active enrollment');

    // The membership year must be the enrollment's year.
    $otherYear = phase9LibCalendar('2032-03-15');
    $enrollment = phase9LibEnrollment($calendar['academic_year_id']);

    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'student',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $otherYear['academic_year_id'],
        'joined_on' => '2031-01-10',
        'enrollment_id' => (int) $enrollment->getKey(),
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'academic year');
});

it('enforces the §10.3 identity CHECK at the database, past any Action', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $enrollment = phase9LibEnrollment($calendar['academic_year_id']);

    // A "student" row with no enrollment violates the CHECK even on a raw insert.
    expect(fn () => DB::table('library_members')->insert([
        'member_no' => 'LM/2031/99901',
        'member_type' => 'student',
        'student_id' => (int) $enrollment->student_id,
        'staff_member_id' => null,
        'enrollment_id' => null,
        'academic_year_id' => $calendar['academic_year_id'],
        'membership_class_id' => (int) $class->getKey(),
        'status' => 'active',
        'joined_on' => '2031-01-10',
        'created_by' => (int) $user->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);

    // An "external" row without name+contact violates it too.
    expect(fn () => DB::table('library_members')->insert([
        'member_no' => 'LM/2031/99902',
        'member_type' => 'external',
        'student_id' => null,
        'staff_member_id' => null,
        'enrollment_id' => null,
        'academic_year_id' => $calendar['academic_year_id'],
        'membership_class_id' => (int) $class->getKey(),
        'status' => 'active',
        'joined_on' => '2031-01-10',
        'external_name' => null,
        'external_contact' => null,
        'created_by' => (int) $user->getKey(),
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('allows exactly one membership per enrollment - the enrollment IS the year scope', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $enrollment = phase9LibEnrollment($calendar['academic_year_id']);

    phase9LibStudentMember($user, $calendar['academic_year_id'], $class, $enrollment);

    expect(fn () => phase9LibStudentMember($user, $calendar['academic_year_id'], $class, $enrollment))
        ->toThrow(QueryException::class);
});

it('enrolls staff once per year, refuses inactive staff, and demands identity for externals', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();

    $staffId = (int) \App\Modules\HR\Models\StaffMember::factory()->create()->getKey();

    $payload = [
        'member_type' => 'staff',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'staff_member_id' => $staffId,
    ];

    $member = app(EnrollLibraryMember::class)->handle($payload, phase9LibActor($user));

    expect($member->member_type)->toBe(MemberType::Staff)
        ->and($member->staff_member_id)->toBe($staffId)
        ->and($member->student_id)->toBeNull()
        ->and($member->enrollment_id)->toBeNull();

    // Same staff, same year: the DB unique key refuses the double.
    expect(fn () => app(EnrollLibraryMember::class)->handle($payload, phase9LibActor($user)))
        ->toThrow(QueryException::class);

    // Inactive staff never joins.
    $inactiveId = (int) \App\Modules\HR\Models\StaffMember::factory()->inactive()->create()->getKey();

    expect(fn () => app(EnrollLibraryMember::class)->handle([
        ...$payload,
        'staff_member_id' => $inactiveId,
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'not active');

    // Externals must carry a name AND a contact (§10.3 CHECK, Action-first).
    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'external',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'external_name' => 'Alumni Reader',
        'external_contact' => '  ',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'external member requires');

    $external = app(EnrollLibraryMember::class)->handle([
        'member_type' => 'external',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'external_name' => 'Alumni Reader',
        'external_contact' => '+237 690 00 00 00',
    ], phase9LibActor($user));

    expect($external->member_type)->toBe(MemberType::External)
        ->and($external->student_id)->toBeNull()
        ->and($external->staff_member_id)->toBeNull();
});

it('refuses an archived membership class and replays idempotently', function (): void {
    $user = phase9LibLibrarian();
    $calendar = phase9LibCalendar();
    $archived = phase9LibMembershipClass(['is_archived' => true]);

    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'external',
        'membership_class_id' => (int) $archived->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'external_name' => 'X',
        'external_contact' => 'Y',
    ], phase9LibActor($user)))->toThrow(DomainException::class, 'active membership class');

    $class = phase9LibMembershipClass();
    $enrollment = phase9LibEnrollment($calendar['academic_year_id']);

    $first = phase9LibStudentMember($user, $calendar['academic_year_id'], $class, $enrollment, [
        'idempotency_key' => 'p9f4-member-1',
    ]);

    // Replaying the SAME request returns the SAME member, creates nothing.
    $replay = app(EnrollLibraryMember::class)->handle([
        'member_type' => 'student',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'enrollment_id' => (int) $enrollment->getKey(),
        'idempotency_key' => 'p9f4-member-1',
    ], phase9LibActor($user));

    expect((int) $replay->getKey())->toBe((int) $first->getKey())
        ->and(LibraryMember::query()->count())->toBe(1);
});

it('denies membership management without library.manage', function (): void {
    $calendar = phase9LibCalendar();
    $class = phase9LibMembershipClass();
    $user = phase9LibUser(LibraryPermission::VIEW, LibraryPermission::CIRCULATE);

    expect(fn () => app(EnrollLibraryMember::class)->handle([
        'member_type' => 'external',
        'membership_class_id' => (int) $class->getKey(),
        'academic_year_id' => $calendar['academic_year_id'],
        'joined_on' => '2031-01-10',
        'external_name' => 'X',
        'external_contact' => 'Y',
    ], phase9LibActor($user)))->toThrow(AuthorizationException::class);
});
