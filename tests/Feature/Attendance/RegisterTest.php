<?php

declare(strict_types=1);

use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Attendance\Actions\AmendAttendanceRegister;
use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Domain\RegisterStatus;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceRegister;
use App\Modules\Identity\Domain\Role;
use App\Modules\Identity\Models\AuditLog;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

it('opens a register on a teaching day with the roster frozen as expected_count', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 5);
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
    );

    expect($register->expected_count)->toBe(5)
        ->and($register->status)->toBe(RegisterStatus::Open)
        ->and($register->present_count)->toBe(5)
        ->and($register->timetable_slot_id)->toBe(AttendanceRegister::SLOT_NONE)
        ->and($register->academic_year_id)->toBe((int) $fixture['year']->getKey());
});

it('is idempotent while open - a re-open returns the same header, never a second', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 3);
    actingAs(phase8F2Teacher($fixture));

    $first = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    $second = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    expect($second->getKey())->toBe($first->getKey())
        ->and(AttendanceRegister::query()->count())->toBe(1);
});

it('blocks a register on an unseeded calendar date instead of defaulting to teaching', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);
    actingAs(phase8F2Teacher($fixture));

    // 2026-10-01 is inside the year but was never seeded.
    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-10-01',
    ))->toThrow(ValidationException::class, 'calendar');
});

it('refuses a holiday register for a teacher and allows it for leadership with a reason', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);

    // 2026-09-21 is the seeded public holiday.
    actingAs(phase8F2Teacher($fixture));
    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-21',
    ))->toThrow(ValidationException::class);

    // The Censeur holds attendance.amend — the override permission — but
    // still owes a reason.
    actingAs(phase8F2UserAs(Role::VicePrincipal));
    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-21',
    ))->toThrow(ValidationException::class);

    $register = app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-21',
        overrideReason: 'Saturday-style make-up classes held on the holiday',
    );

    expect($register->status)->toBe(RegisterStatus::Open);
});

it('gates opening on a live teaching assignment - an unassigned teacher is refused', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);

    // A Teacher with NO subject allocation for this level.
    actingAs(phase8F2UserAs(Role::Teacher));

    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
    ))->toThrow(AuthorizationException::class);
});

it('stores exception rows only on submit - present is never written - and maintains the header counts', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 6);
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'present'],
        ['enrollment_id' => (int) $enrollments[1]->getKey(), 'status' => 'absent'],
        ['enrollment_id' => (int) $enrollments[2]->getKey(), 'status' => 'late', 'minutes_late' => 12],
        ['enrollment_id' => (int) $enrollments[3]->getKey(), 'status' => 'excused'],
        ['enrollment_id' => (int) $enrollments[4]->getKey(), 'status' => 'sick'],
        // enrollment 5 not sent at all — silently present.
    ]);

    $register->refresh();

    // 6 expected − 4 exception rows (absent, late, excused, sick).
    expect($register->status)->toBe(RegisterStatus::Submitted)
        ->and($register->present_count)->toBe(2)
        ->and($register->absent_count)->toBe(2)   // absent + sick
        ->and($register->late_count)->toBe(1)
        ->and($register->excused_count)->toBe(1)
        ->and($register->expected_count)->toBe(6);

    // §9.4's storage rule: no present row, ever.
    expect(AttendanceRecord::query()->count())->toBe(4)
        ->and(AttendanceRecord::query()->where('status', 'present')->count())->toBe(0);

    $late = AttendanceRecord::query()->where('status', 'late')->firstOrFail();
    expect($late->minutes_late)->toBe(12);
});

it('rejects a double-tapped submit with the conditional-update affected-rows check', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 2);
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    $marks = [['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'absent']];

    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), $marks);

    expect(fn () => app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), $marks))
        ->toThrow(ValidationException::class, 'already been submitted');

    // The double tap wrote nothing twice.
    expect(AttendanceRecord::query()->count())->toBe(1);
});

it('rejects marks for an enrollment that is not on the roster', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 1);
    $stranger = phase8F2Enroll($fixture, 1, '2026-09-10')[0]; // enrolled AFTER the register date
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    expect(fn () => app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $stranger->getKey(), 'status' => 'absent'],
    ]))->toThrow(ValidationException::class, 'roster');
});

it('never deletes a submitted register - the model observer blocks it', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);
    actingAs(phase8F2Teacher($fixture));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), []);

    expect(fn () => $register->refresh()->delete())->toThrow(RuntimeException::class, 'cannot be deleted');
    expect(AttendanceRegister::query()->count())->toBe(1);

    // An OPEN register is still a draft and may be discarded.
    $draft = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-08');
    $draft->delete();
    expect(AttendanceRegister::query()->count())->toBe(1);
});

it('amends only with a reason, replaces the rows, flips to amended and audits before/after', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 3);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'absent'],
    ]);

    // No reason, no amendment.
    expect(fn () => app(AmendAttendanceRegister::class)->handle((int) $register->getKey(), [], '  '))
        ->toThrow(ValidationException::class, 'why');

    app(AmendAttendanceRegister::class)->handle(
        (int) $register->getKey(),
        [
            ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'present'],
            ['enrollment_id' => (int) $enrollments[1]->getKey(), 'status' => 'late'],
        ],
        'Teacher marked the wrong student absent',
    );

    $register->refresh();

    expect($register->status)->toBe(RegisterStatus::Amended)
        ->and($register->present_count)->toBe(2)
        ->and($register->absent_count)->toBe(0)
        ->and($register->late_count)->toBe(1)
        ->and($register->amendment_reason)->toBe('Teacher marked the wrong student absent');

    expect(AttendanceRecord::query()->where('status', 'late')->count())->toBe(1)
        ->and(AttendanceRecord::query()->where('status', 'absent')->count())->toBe(0);

    // The original counts are recoverable from the audit before payload.
    $entry = AuditLog::query()
        ->where('auditable_type', AttendanceRegister::class)
        ->orderByDesc('id')
        ->firstOrFail();
    $before = $entry->before ?? [];
    expect($before['absent_count'] ?? null)->toBe(1);
});

it('a plain teacher cannot amend - attendance.amend is leadership only', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 2);
    $teacher = phase8F2Teacher($fixture);
    actingAs($teacher);

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), []);

    expect(fn () => app(AmendAttendanceRegister::class)->handle(
        (int) $register->getKey(),
        [['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'absent']],
        'trying anyway',
    ))->toThrow(AuthorizationException::class);
});

it('requires a slot in per-lesson mode and rejects one in daily mode', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 2);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // Daily group with a slot: refused.
    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
        timetableSlotId: 12345,
    ))->toThrow(ValidationException::class, 'daily');

    // Flip the group to per-lesson: a slot becomes mandatory.
    \Illuminate\Support\Facades\DB::table('class_groups')
        ->where('id', $fixture['group']->getKey())
        ->update(['attendance_mode' => 'per_lesson']);

    expect(fn () => app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
    ))->toThrow(ValidationException::class, 'per-lesson');

    // With a real slot the register carries the denormalised subject and
    // the period's duration — the source of heures d'absence (§9.7).
    $period = TimetablePeriod::factory()->create([
        'school_section_id' => $fixture['section']->getKey(),
        'duration_minutes' => 55,
    ]);
    $slot = TimetableSlot::factory()->create([
        'class_group_id' => $fixture['group']->getKey(),
        'academic_year_id' => $fixture['year']->getKey(),
        'timetable_period_id' => $period->getKey(),
        'day_of_week' => 1, // 2026-09-07 is a Monday
    ]);

    $register = app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
        timetableSlotId: (int) $slot->getKey(),
    );

    expect($register->timetable_slot_id)->toBe((int) $slot->getKey())
        ->and($register->subject_id)->toBe($slot->subject_id)
        ->and($register->lesson_duration_minutes)->toBe(55)
        ->and($register->mode->value)->toBe('per_lesson');
});
