<?php

declare(strict_types=1);

use App\Modules\Activities\Actions\CloseActivity;
use App\Modules\Activities\Actions\RecordSessionAttendance;
use App\Modules\Activities\Actions\ScheduleSession;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\SessionAttendanceStatus;
use App\Modules\Activities\Models\ActivityAttendance;
use App\Support\Audit\Actor;
use Database\Factories\StaffMemberFactory;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ActivityTestHelpers.php';

uses(RefreshDatabase::class);

// ── ScheduleSession ─────────────────────────────────────────────────────

it('schedules a session with venue and times', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    $session = actvSession($user, $activity);

    expect($session->exists)->toBeTrue()
        ->and($session->activity_id)->toBe((int) $activity->getKey())
        ->and($session->scheduled_on->toDateString())->toBe('2026-09-05')
        ->and((string) $session->starts_at)->toStartWith('15:00')
        ->and($session->venue)->toBe('Sports Field');
});

it('schedules a session with a real staff supervisor', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $staff = StaffMemberFactory::new()->createOne();

    $session = actvSession($user, $activity, ['supervisor_id' => (int) $staff->getKey()]);

    expect($session->supervisor_id)->toBe((int) $staff->getKey());
});

it('refuses a supervisor who is not a staff member', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    expect(fn () => actvSession($user, $activity, ['supervisor_id' => 999_999_999]))
        ->toThrow(DomainException::class, 'supervisor');
});

it('refuses a session on a closed activity', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    app(CloseActivity::class)->handle((int) $activity->getKey(), actvActor($user));

    expect(fn () => actvSession($user, $activity))
        ->toThrow(DomainException::class, 'closed');
});

it('refuses a session that ends before it starts, and one without a date', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    expect(fn () => actvSession($user, $activity, ['starts_at' => '17:00', 'ends_at' => '15:00']))
        ->toThrow(ValidationException::class)
        ->and(fn () => actvSession($user, $activity, ['scheduled_on' => '']))
        ->toThrow(ValidationException::class);
});

it('refuses scheduling without activity.manage', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);

    actvUser(ActivityPermission::VIEW);

    app(ScheduleSession::class)->handle((int) $activity->getKey(), [
        'scheduled_on' => '2026-09-05',
    ], Actor::system());
})->throws(AuthorizationException::class);

// ── RecordSessionAttendance ─────────────────────────────────────────────

it('records the register for a session', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $m1 = actvMembership($user, $activity);
    $m2 = actvMembership($user, $activity);
    $session = actvSession($user, $activity);

    $written = app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
        (int) $m1->getKey() => 'present',
        (int) $m2->getKey() => 'excused',
    ], actvActor($user));

    expect($written)->toBe(2);

    $marks = ActivityAttendance::query()
        ->where('session_id', $session->getKey())
        ->orderBy('membership_id')
        ->get();

    expect($marks)->toHaveCount(2)
        ->and($marks[0]?->status)->toBe(SessionAttendanceStatus::Present)
        ->and($marks[1]?->status)->toBe(SessionAttendanceStatus::Excused);
});

it('re-records a mark as an update, never a second row', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $m = actvMembership($user, $activity);
    $session = actvSession($user, $activity);

    app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
        (int) $m->getKey() => 'absent',
    ], actvActor($user));

    app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
        (int) $m->getKey() => 'present',
    ], actvActor($user));

    $marks = ActivityAttendance::query()->where('session_id', $session->getKey())->get();

    expect($marks)->toHaveCount(1)
        ->and($marks[0]?->status)->toBe(SessionAttendanceStatus::Present);
});

it('refuses a mark for a member of a different activity', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $other = actvActivity($user);
    $foreign = actvMembership($user, $other);
    $session = actvSession($user, $activity);

    expect(fn () => app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
        (int) $foreign->getKey() => 'present',
    ], actvActor($user)))->toThrow(DomainException::class, 'does not belong');
});

it('refuses an empty register and an unknown status', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $m = actvMembership($user, $activity);
    $session = actvSession($user, $activity);

    expect(fn () => app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [], actvActor($user)))
        ->toThrow(ValidationException::class)
        ->and(fn () => app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
            (int) $m->getKey() => 'teleported',
        ], actvActor($user)))->toThrow(ValidationException::class);
});

it('refuses recording attendance without activity.manage', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);
    $m = actvMembership($manager, $activity);
    $session = actvSession($manager, $activity);

    actvUser(ActivityPermission::VIEW);

    app(RecordSessionAttendance::class)->handle((int) $session->getKey(), [
        (int) $m->getKey() => 'present',
    ], Actor::system());
})->throws(AuthorizationException::class);
