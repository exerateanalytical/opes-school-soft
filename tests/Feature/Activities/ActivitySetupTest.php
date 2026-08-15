<?php

declare(strict_types=1);

use App\Modules\Activities\Actions\CloseActivity;
use App\Modules\Activities\Actions\CreateActivity;
use App\Modules\Activities\Domain\ActivityPermission;
use App\Modules\Activities\Domain\ActivityStatus;
use App\Modules\Activities\Domain\ActivityType;
use App\Modules\Activities\Domain\ConsentStatus;
use App\Modules\Activities\Domain\MembershipStatus;
use App\Support\Audit\Actor;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/ActivityTestHelpers.php';

uses(RefreshDatabase::class);

// ── CreateActivity ──────────────────────────────────────────────────────

it('creates a club through the gate', function () {
    $user = actvManager();

    $activity = actvActivity($user, ['name' => 'Chess Club', 'capacity' => 20]);

    expect($activity->exists)->toBeTrue()
        ->and($activity->name)->toBe('Chess Club')
        ->and($activity->type)->toBe(ActivityType::Club)
        ->and($activity->status)->toBe(ActivityStatus::Active)
        ->and($activity->capacity)->toBe(20)
        ->and($activity->destination)->toBeNull();
});

it('creates an excursion carrying its trip envelope', function () {
    $user = actvManager();

    $excursion = actvExcursion($user, ['name' => 'Limbe Trip']);

    expect($excursion->type)->toBe(ActivityType::Excursion)
        ->and($excursion->destination)->toBe('Limbe Wildlife Centre')
        ->and($excursion->departure_at?->toDateTimeString())->toBe('2026-09-10 07:00:00')
        ->and($excursion->return_at?->toDateTimeString())->toBe('2026-09-10 18:00:00');
});

it('refuses activity creation without activity.manage', function () {
    actvUser(ActivityPermission::VIEW); // signed in, view only

    app(CreateActivity::class)->handle([
        'name' => 'Refused Club',
        'type' => 'club',
    ], Actor::system());
})->throws(AuthorizationException::class);

it('refuses an excursion without a destination', function () {
    $user = actvManager();

    expect(fn () => actvExcursion($user, ['destination' => '']))
        ->toThrow(ValidationException::class);
});

it('refuses an excursion whose return precedes its departure', function () {
    $user = actvManager();

    expect(fn () => actvExcursion($user, [
        'departure_at' => '2026-09-10 07:00',
        'return_at' => '2026-09-09 18:00',
    ]))->toThrow(ValidationException::class);
});

it('refuses excursion fields on a club', function () {
    $user = actvManager();

    expect(fn () => actvActivity($user, ['destination' => 'Kribi Beach']))
        ->toThrow(ValidationException::class);
});

it('refuses an unknown type and a blank name', function () {
    $user = actvManager();

    expect(fn () => actvActivity($user, ['type' => 'karaoke']))
        ->toThrow(ValidationException::class)
        ->and(fn () => actvActivity($user, ['name' => '  ']))
        ->toThrow(ValidationException::class);
});

it('rejects excursion decoration at the schema layer too', function () {
    actvManager();

    // Straight past the Action: the CHECK still refuses a club with a
    // departure time.
    expect(fn () => DB::table('activities')->insert([
        'name' => 'Smuggled Trip',
        'type' => 'club',
        'status' => 'active',
        'departure_at' => '2026-09-10 07:00:00',
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(Illuminate\Database\QueryException::class);
});

// ── CloseActivity ───────────────────────────────────────────────────────

it('closes an activity and ends every live membership with it', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    $m1 = actvMembership($user, $activity);
    $m2 = actvMembership($user, $activity);

    $closed = app(CloseActivity::class)->handle((int) $activity->getKey(), actvActor($user));

    expect($closed->status)->toBe(ActivityStatus::Closed)
        ->and($m1->refresh()->status)->toBe(MembershipStatus::Ended)
        ->and($m1->ends_on)->not->toBeNull()
        ->and($m2->refresh()->status)->toBe(MembershipStatus::Ended);
});

it('refuses to close an activity twice', function () {
    $user = actvManager();
    $activity = actvActivity($user);

    app(CloseActivity::class)->handle((int) $activity->getKey(), actvActor($user));

    expect(fn () => app(CloseActivity::class)->handle((int) $activity->getKey(), actvActor($user)))
        ->toThrow(DomainException::class, 'already closed');
});

it('refuses closing without activity.manage', function () {
    $manager = actvManager();
    $activity = actvActivity($manager);

    actvUser(ActivityPermission::VIEW);

    app(CloseActivity::class)->handle((int) $activity->getKey(), Actor::system());
})->throws(AuthorizationException::class);

it('keeps an excursion membership consent pending until decided', function () {
    $user = actvManager();
    $excursion = actvExcursion($user);

    $membership = actvMembership($user, $excursion);

    expect($membership->consent_status)->toBe(ConsentStatus::Pending);
});
