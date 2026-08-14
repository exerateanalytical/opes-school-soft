<?php

declare(strict_types=1);

use App\Modules\Activities\Actions\RecordConsent;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/ActivityTestHelpers.php';

uses(RefreshDatabase::class);

it('records a granted consent from a linked guardian', function () {
    $user = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $membership = actvMembership($user, actvExcursion($user), $studentId);

    $updated = app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $guardianId,
        ConsentStatus::Granted,
        'Signed slip returned',
        actvActor($user),
    );

    expect($updated->consent_status)->toBe(ConsentStatus::Granted)
        ->and($updated->consent_guardian_id)->toBe($guardianId)
        ->and($updated->consent_recorded_at)->not->toBeNull()
        ->and($updated->consent_note)->toBe('Signed slip returned');
});

it('records a declined consent too - a refusal is a decision, not an absence', function () {
    $user = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $membership = actvMembership($user, actvExcursion($user), $studentId);

    $updated = app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $guardianId,
        ConsentStatus::Declined,
        null,
        actvActor($user),
    );

    expect($updated->consent_status)->toBe(ConsentStatus::Declined)
        ->and($updated->consent_note)->toBeNull();
});

it('refuses consent on anything that is not an excursion', function () {
    $user = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $membership = actvMembership($user, actvActivity($user), $studentId);

    expect(fn () => app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $guardianId,
        ConsentStatus::Granted,
        null,
        actvActor($user),
    ))->toThrow(DomainException::class, 'excursions only');
});

it('refuses a guardian with no current link to the student', function () {
    $user = actvManager();
    $membership = actvMembership($user, actvExcursion($user));
    $strangerId = actvUnlinkedGuardianId();

    expect(fn () => app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $strangerId,
        ConsentStatus::Granted,
        null,
        actvActor($user),
    ))->toThrow(DomainException::class, 'no current link');
});

it('refuses pending as a decision', function () {
    $user = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $membership = actvMembership($user, actvExcursion($user), $studentId);

    expect(fn () => app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $guardianId,
        ConsentStatus::Pending,
        null,
        actvActor($user),
    ))->toThrow(DomainException::class);
});

it('refuses recording consent without activity.manage', function () {
    $manager = actvManager();
    $studentId = actvStudentId();
    $guardianId = actvLinkedGuardianId($studentId);
    $membership = actvMembership($manager, actvExcursion($manager), $studentId);

    actvUser(ActivityPermission::VIEW);

    app(RecordConsent::class)->handle(
        (int) $membership->getKey(),
        $guardianId,
        ConsentStatus::Granted,
        null,
        Actor::system(),
    );
})->throws(AuthorizationException::class);
