<?php

declare(strict_types=1);

use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Students\Domain\EnrollmentStatus;
use App\Modules\Students\Models\EnrollmentSegment;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

// §9.5: the roster is resolved through enrollment_segments, in-transaction,
// and frozen on the header. These tests each break one predicate of the
// roster query and assert the denominator answers correctly.

it('excludes a student enrolled after the register date', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 3);                       // on the roster
    phase8F2Enroll($fixture, 2, '2026-09-10');         // enrolled later
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    expect($register->expected_count)->toBe(3);
});

it('excludes a student who left before the register date', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);

    // One withdraws on the 3rd: their segment closes and left_on is set.
    $left = $enrollments[0];
    $left->update(['status' => EnrollmentStatus::Withdrawn, 'left_on' => '2026-09-03']);
    EnrollmentSegment::query()
        ->where('enrollment_id', $left->getKey())
        ->whereNull('ends_on')
        ->update(['ends_on' => '2026-09-03']);

    actingAs(phase8F2Teacher($fixture));
    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    expect($register->expected_count)->toBe(2);
});

it('keeps suspended students in expected_count - excluding them would inflate the rate', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    $enrollments[0]->update(['status' => EnrollmentStatus::Suspended]);

    actingAs(phase8F2Teacher($fixture));
    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    expect($register->expected_count)->toBe(3);

    // And the suspended student is recordable as `suspended` on submit.
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'suspended'],
    ]);

    expect($register->refresh()->present_count)->toBe(2);
});

it('resolves a mid-year transfer date-sensitively - one enrollment, one class per date', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 2);
    $mover = $enrollments[0];

    // A second group at the same level; the student transfers effective the
    // 15th — C2's segment arithmetic: close on the 14th, open on the 15th.
    $other = \App\Modules\Academics\Models\ClassGroup::factory()->create([
        'class_level_id' => $fixture['level']->getKey(),
        'academic_year_id' => $fixture['year']->getKey(),
        'stream_id' => null,
        'capacity' => 60,
    ]);

    EnrollmentSegment::query()
        ->where('enrollment_id', $mover->getKey())
        ->whereNull('ends_on')
        ->update(['ends_on' => '2026-09-14']);

    EnrollmentSegment::factory()->create([
        'enrollment_id' => $mover->getKey(),
        'class_group_id' => $other->getKey(),
        'starts_on' => '2026-09-15',
        'ends_on' => null,
        'reason' => \App\Modules\Students\Domain\SegmentReason::ClassTransfer,
    ]);

    actingAs(phase8F2UserAs(\App\Modules\Identity\Domain\Role::VicePrincipal));

    // Before the transfer: the old group still owns them.
    $before = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-10');
    expect($before->expected_count)->toBe(2);

    // After: the old group is down to one, the new group counts the mover.
    $afterOld = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-16');
    $afterNew = app(OpenAttendanceRegister::class)->handle((int) $other->getKey(), '2026-09-16');

    expect($afterOld->expected_count)->toBe(1)
        ->and($afterNew->expected_count)->toBe(1);
});

it('freezes expected_count at open - later enrollments never rewrite an old denominator', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 4);
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    expect($register->expected_count)->toBe(4);

    // Two more students join the class group days later (backdated segments
    // would be a roster CORRECTION, which is the amendment path).
    phase8F2Enroll($fixture, 2, '2026-09-09');

    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), []);

    expect($register->refresh()->expected_count)->toBe(4)
        ->and($register->present_count)->toBe(4);
});
