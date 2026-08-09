<?php

declare(strict_types=1);

use App\Modules\Attendance\Actions\GetAttendanceRateForEnrollments;
use App\Modules\Attendance\Actions\OpenAttendanceRegister;
use App\Modules\Attendance\Actions\SubmitAttendanceRegister;
use App\Modules\Attendance\Livewire\Index as AttendanceIndex;
use App\Modules\Attendance\Models\AttendanceSummary;
use App\Modules\Identity\Domain\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

require_once __DIR__.'/AttendanceTestHelpers.php';

uses(RefreshDatabase::class);

// §9.6 — one formula, stated once. Each test drives real registers through
// the Actions and asserts the read door's number.

if (! function_exists('phase8F2TakeRegisters')) {
    /**
     * Takes registers on the given teaching dates with per-enrollment marks.
     *
     * @param  array{group: \App\Modules\Academics\Models\ClassGroup}  $fixture
     * @param  array<string, array<int, string>>  $days date => [enrollment id => status]
     */
    function phase8F2TakeRegisters(array $fixture, array $days): void
    {
        foreach ($days as $date => $marks) {
            $register = app(OpenAttendanceRegister::class)->handle(
                (int) $fixture['group']->getKey(),
                $date,
            );

            $payload = [];

            foreach ($marks as $enrollmentId => $status) {
                $payload[] = ['enrollment_id' => $enrollmentId, 'status' => $status];
            }

            app(SubmitAttendanceRegister::class)->handle((int) $register->getKey(), $payload);
        }
    }
}

it('counts late as PRESENT', function () {
    $fixture = phase8F2Fixture();
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    phase8F2TakeRegisters($fixture, [
        '2026-09-07' => [(int) $e->getKey() => 'late'],
        '2026-09-08' => [],
        '2026-09-09' => [],
        '2026-09-10' => [],
    ]);

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), [(int) $e->getKey()]);

    // 4 sessions, 1 late — late is present: 4/4, not 3/4.
    expect($rates[(int) $e->getKey()])->toBe(1.0);
});

it('keeps excused OUT of the numerator but IN the denominator', function () {
    $fixture = phase8F2Fixture();
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    phase8F2TakeRegisters($fixture, [
        '2026-09-07' => [(int) $e->getKey() => 'excused'],
        '2026-09-08' => [],
        '2026-09-09' => [],
        '2026-09-10' => [],
    ]);

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), [(int) $e->getKey()]);

    expect($rates[(int) $e->getKey()])->toBe(0.75);
});

it('removes suspended sessions from BOTH numerator and denominator', function () {
    $fixture = phase8F2Fixture();
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    phase8F2TakeRegisters($fixture, [
        '2026-09-07' => [(int) $e->getKey() => 'suspended'],
        '2026-09-08' => [],
        '2026-09-09' => [],
        '2026-09-10' => [],
    ]);

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), [(int) $e->getKey()]);

    // 3 present out of (4 − 1): the suspension is not held against the child.
    expect($rates[(int) $e->getKey()])->toBe(1.0);
});

it('counts absent and sick as countable absences', function () {
    $fixture = phase8F2Fixture();
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    phase8F2TakeRegisters($fixture, [
        '2026-09-07' => [(int) $e->getKey() => 'absent'],
        '2026-09-08' => [(int) $e->getKey() => 'sick'],
        '2026-09-09' => [],
        '2026-09-10' => [],
    ]);

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), [(int) $e->getKey()]);

    expect($rates[(int) $e->getKey()])->toBe(0.5);
});

it('C5 counterexample: zero registers taken means a NULL rate for every student, never 100%', function () {
    $fixture = phase8F2Fixture();
    $enrollments = phase8F2Enroll($fixture, 45);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // The teacher never opens a register — v1 gave all 45 students 100%
    // and passed them through the promotion attendance criterion.

    $ids = array_map(static fn ($e): int => (int) $e->getKey(), $enrollments);

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), $ids);

    expect($rates)->toHaveCount(45);

    foreach ($ids as $id) {
        expect($rates[$id])->toBeNull();
    }
});

it('an open draft register creates no expectation - only taken registers count', function () {
    $fixture = phase8F2Fixture();
    [$e] = phase8F2Enroll($fixture, 1);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // Opened but never submitted.
    app(OpenAttendanceRegister::class)->handle((int) $fixture['group']->getKey(), '2026-09-07');

    $rates = app(GetAttendanceRateForEnrollments::class)
        ->handle((int) $fixture['year']->getKey(), [(int) $e->getKey()]);

    expect($rates[(int) $e->getKey()])->toBeNull();
});

it('renders the NULL rate as an em dash on the management screen, never 0%', function () {
    $fixture = phase8F2Fixture();
    phase8F2Enroll($fixture, 3);
    actingAs(phase8F2UserAs(Role::VicePrincipal));

    // No registers at all: Rate This Month must be "—".
    Livewire::test(AttendanceIndex::class)
        ->assertSee('—')
        ->assertDontSee('0.0%')
        ->assertDontSee('0%');
});

it('the summary model states the same formula - zero denominator is NULL', function () {
    $summary = new AttendanceSummary([
        'sessions_expected' => 0,
        'sessions_present' => 0,
        'sessions_suspended' => 0,
    ]);

    expect($summary->attendanceRate())->toBeNull();

    $summary->sessions_expected = 10;
    $summary->sessions_present = 9;
    expect($summary->attendanceRate())->toBe(0.9);

    // Fully suspended period: denominator collapses to zero — NULL again.
    $summary->sessions_expected = 2;
    $summary->sessions_present = 0;
    $summary->sessions_suspended = 2;
    expect($summary->attendanceRate())->toBeNull();
});
