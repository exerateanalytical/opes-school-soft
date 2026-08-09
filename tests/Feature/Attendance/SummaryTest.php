<?php

declare(strict_types=1);

use App\Modules\Academics\Models\TimetablePeriod;
use App\Modules\Academics\Models\TimetableSlot;
use App\Modules\Attendance\Actions\AmendAttendanceRegister;
use App\Modules\Attendance\Actions\GetAttendanceSummary;
use App\Modules\Attendance\Actions\JustifyAbsence;
use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Domain\JustificationType;
use App\Modules\Attendance\Models\AttendanceRecord;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

// §9.8: summaries are PERSISTED per (enrollment, period), rebuilt by the
// queued job on submit/amend/justify. The queue is sync in tests, so the
// rebuild has already run by the time each assertion executes.

it('persists a per-period summary on register submit', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    $enrollments = phase8F2Enroll($fixture, 3);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $enrollments[0]->getKey(), 'status' => 'absent'],
        ['enrollment_id' => (int) $enrollments[1]->getKey(), 'status' => 'late'],
    ]);

    $summary = AttendanceSummary::query()
        ->where('enrollment_id', $enrollments[0]->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect($summary->sessions_expected)->toBe(1)
        ->and($summary->sessions_absent)->toBe(1)
        ->and($summary->sessions_present)->toBe(0);

    // The late student is present in the §9.6 sense.
    $late = AttendanceSummary::query()
        ->where('enrollment_id', $enrollments[1]->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect($late->sessions_expected)->toBe(1)
        ->and($late->sessions_late)->toBe(1)
        ->and($late->retards)->toBe(1)
        ->and($late->sessions_present)->toBe(1)
        ->and($late->attendanceRate())->toBe(1.0);

    // The silently-present student has a row too — expected 1, present 1.
    $present = AttendanceSummary::query()
        ->where('enrollment_id', $enrollments[2]->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect($present->sessions_present)->toBe(1);
});

it('upserts on UNIQUE(enrollment, period) - a rebuild converges instead of duplicating', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    $r1 = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $r1->getKey(), [
        ['enrollment_id' => (int) $e->getKey(), 'status' => 'absent'],
    ]);

    $r2 = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-08');
    app(SubmitAttendanceRegister::class)->handle((int) $r2->getKey(), []);

    expect(AttendanceSummary::query()
        ->where('enrollment_id', $e->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->count())->toBe(1);

    $summary = AttendanceSummary::query()
        ->where('enrollment_id', $e->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect($summary->sessions_expected)->toBe(2)
        ->and($summary->sessions_absent)->toBe(1)
        ->and($summary->sessions_present)->toBe(1)
        ->and($summary->attendanceRate())->toBe(0.5);
});

it('re-runs the rebuild after an amendment - the summary tracks the corrected register', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $e->getKey(), 'status' => 'absent'],
    ]);

    app(AmendAttendanceRegister::class)->handle(
        (int) $register->getKey(),
        [['enrollment_id' => (int) $e->getKey(), 'status' => 'present']],
        'Marked absent in error',
    );

    $summary = AttendanceSummary::query()
        ->where('enrollment_id', $e->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect($summary->sessions_absent)->toBe(0)
        ->and($summary->sessions_present)->toBe(1);
});

it('accrues heures d\'absence from per-lesson registers, split by is_justified', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    DB::table('class_groups')
        ->where('id', $fixture['group']->getKey())
        ->update(['attendance_mode' => 'per_lesson']);

    $bell = TimetablePeriod::factory()->create([
        'school_section_id' => $fixture['section']->getKey(),
        'duration_minutes' => 90,
    ]);
    $slot = TimetableSlot::factory()->create([
        'class_group_id' => $fixture['group']->getKey(),
        'academic_year_id' => $fixture['year']->getKey(),
        'timetable_period_id' => $bell->getKey(),
        'day_of_week' => 1,
    ]);

    $register = app(OpenAttendanceRegister::class)->handle(
        (int) $fixture['group']->getKey(),
        '2026-09-07',
        timetableSlotId: (int) $slot->getKey(),
    );
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $e->getKey(), 'status' => 'absent'],
    ]);

    $summary = AttendanceSummary::query()
        ->where('enrollment_id', $e->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    // 90 unjustified minutes = 1.5 h.
    expect((float) $summary->hours_absent_unjustified)->toBe(1.5)
        ->and((float) $summary->hours_absent_justified)->toBe(0.0);

    // A justification received after the fact moves the hours across the
    // split — the status stays absent (§9.7).
    $record = AttendanceRecord::query()
        ->where('attendance_register_id', $register->getKey())
        ->firstOrFail();
    app(JustifyAbsence::class)->handle((int) $record->getKey(), JustificationType::Medical);

    $summary->refresh();
    expect((float) $summary->hours_absent_justified)->toBe(1.5)
        ->and((float) $summary->hours_absent_unjustified)->toBe(0.0);
});

it('daily registers accrue NO hours - daily attendance cannot yield heures d\'absence', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $e->getKey(), 'status' => 'absent'],
    ]);

    $summary = AttendanceSummary::query()
        ->where('enrollment_id', $e->getKey())
        ->where('assessment_period_id', $period->getKey())
        ->firstOrFail();

    expect((float) $summary->hours_absent_unjustified)->toBe(0.0)
        ->and($summary->sessions_absent)->toBe(1);
});

it('serves the summary through the GetAttendanceSummary read door as a plain array', function () {
    $fixture = phase8F2Fixture();
    $period = phase8F2Period($fixture);
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // Nothing computed yet: the door answers null, not zeros.
    expect(app(GetAttendanceSummary::class)->handle((int) $e->getKey(), (int) $period->getKey()))
        ->toBeNull();

    $register = app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');
    app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), [
        ['enrollment_id' => (int) $e->getKey(), 'status' => 'excused'],
    ]);

    $answer = app(GetAttendanceSummary::class)->handle((int) $e->getKey(), (int) $period->getKey());

    expect($answer)->not->toBeNull()
        ->and($answer['sessions_expected'] ?? null)->toBe(1)
        ->and($answer['sessions_excused'] ?? null)->toBe(1)
        ->and($answer['sessions_present'] ?? null)->toBe(0)
        ->and($answer['attendance_rate'] ?? 'missing')->toBe(0.0);
});
