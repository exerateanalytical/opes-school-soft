<?php

declare(strict_types=1);

use App\Modules\Activities\Actions\CloseActivity;
use App\Modules\Activities\Actions\EnrolStudent;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Domain\MembershipRole;
use App\Modules\Activities\Domain\MembershipStatus;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

require_once __DIR__.'/ActivityTestHelpers.php';

uses(RefreshDatabase::class);

it('enrols a student with a role and start date', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $studentId = actvStudentId();

    $membership = app(EnrolStudent::class)->handle(
        (int) $activity->getKey(),
        $studentId,
        MembershipRole::Captain,
        Carbon::parse('2026-09-01'),
        actvActor($user),
    );

    expect($membership->exists)->toBeTrue()
        ->and($membership->student_id)->toBe($studentId)
        ->and($membership->role)->toBe(MembershipRole::Captain)
        ->and($membership->status)->toBe(MembershipStatus::Active)
        ->and($membership->starts_on->toDateString())->toBe('2026-09-01')
        // A club carries no consent state at all.
        ->and($membership->consent_status)->toBeNull();
});

it('refuses enrolment without activity.manage', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);
    $studentId = actvStudentId();

    actvUser(ActivityPermission::VIEW);

    app(EnrolStudent::class)->handle(
        (int) $activity->getKey(),
        $studentId,
        MembershipRole::Member,
        Carbon::parse('2026-09-01'),
        Actor::system(),
    );
})->throws(AuthorizationException::class);

it('refuses a student who does not exist', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    expect(fn () => app(EnrolStudent::class)->handle(
        (int) $activity->getKey(),
        999_999_999,
        MembershipRole::Member,
        Carbon::parse('2026-09-01'),
        actvActor($user),
    ))->toThrow(DomainException::class, 'does not exist');
});

it('refuses a double enrolment in the same activity', function () {
    $user = actvManager();
    $activity = actvActivity($user);
    $studentId = actvStudentId();

    actvMembership($user, $activity, $studentId);

    expect(fn () => actvMembership($user, $activity, $studentId))
        ->toThrow(DomainException::class, 'already an active member');
});

it('allows the same student in two different activities', function () {
    $user = actvManager();
    $studentId = actvStudentId();

    $a = actvMembership($user, actvActivity($user), $studentId);
    $b = actvMembership($user, actvActivity($user), $studentId);

    expect($a->status)->toBe(MembershipStatus::Active)
        ->and($b->status)->toBe(MembershipStatus::Active);
});

it('refuses enrolment into a closed activity', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    app(CloseActivity::class)->handle((int) $activity->getKey(), actvActor($user));

    expect(fn () => actvMembership($user, $activity))
        ->toThrow(DomainException::class, 'closed');
});

it('refuses enrolment past a set capacity', function () {
    $user = actvManager();
    $activity = actvActivity($user, ['capacity' => 2]);

    actvMembership($user, $activity);
    actvMembership($user, $activity);

    expect(fn () => actvMembership($user, $activity))
        ->toThrow(DomainException::class, 'full');
});

it('stamps consent pending on an excursion seat only', function () {
    $user = actvManager();

    $club = actvMembership($user, actvActivity($user));
    $trip = actvMembership($user, actvExcursion($user));

    expect($club->consent_status)->toBeNull()
        ->and($trip->consent_status)->toBe(ConsentStatus::Pending);
});
