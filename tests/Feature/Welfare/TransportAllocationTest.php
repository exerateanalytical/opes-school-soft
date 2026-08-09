<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\AllocateTransport;
use App\Modules\Welfare\Actions\EndTransportAllocation;
use App\Modules\Welfare\Actions\TransportRosterReport;
use App\Modules\Welfare\Domain\AllocationStatus;
use App\Modules\Welfare\Domain\TransportDirection;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportAllocation;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

require_once __DIR__.'/TransportTestHelpers.php';

uses(RefreshDatabase::class);

it('allocates an active enrollment to a stop on a route', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stopId = p10TransportStopIds($route)[1];
    $enrollmentId = p10TransportEnrollmentId();

    $allocation = app(AllocateTransport::class)->handle(
        $enrollmentId,
        (int) $route->getKey(),
        $stopId,
        TransportDirection::Both,
        Carbon::parse('2026-09-10'),
        p10TransportActor($user),
    );

    expect($allocation->status)->toBe(AllocationStatus::Active)
        ->and($allocation->enrollment_id)->toBe($enrollmentId)
        ->and($allocation->stop_id)->toBe($stopId)
        // The generated column carries the enrollment while active.
        ->and((int) DB::table('transport_allocations')->where('id', $allocation->getKey())->value('active_key'))
        ->toBe($enrollmentId);
});

it('refuses allocation without transport.manage', function () {
    $manager = p10TransportManager();
    $route = p10TransportRoute($manager);
    $stopId = p10TransportStopIds($route)[0];
    $enrollmentId = p10TransportEnrollmentId();

    p10TransportUser(TransportPermission::VIEW); // now signed in without manage

    app(AllocateTransport::class)->handle(
        $enrollmentId,
        (int) $route->getKey(),
        $stopId,
        TransportDirection::Both,
        Carbon::parse('2026-09-10'),
        \App\Support\Audit\Actor::system(),
    );
})->throws(AuthorizationException::class);

it('rejects an enrollment that is not active', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stopId = p10TransportStopIds($route)[0];

    app(AllocateTransport::class)->handle(
        p10TransportWithdrawnEnrollmentId(),
        (int) $route->getKey(),
        $stopId,
        TransportDirection::Both,
        Carbon::parse('2026-09-10'),
        p10TransportActor($user),
    );
})->throws(DomainException::class, 'ACTIVE enrollment');

it('rejects a stop that belongs to a different route', function () {
    $user = p10TransportManager();
    $routeA = p10TransportRoute($user);
    $routeB = p10TransportRoute($user);
    $foreignStopId = p10TransportStopIds($routeB)[0];

    app(AllocateTransport::class)->handle(
        p10TransportEnrollmentId(),
        (int) $routeA->getKey(),
        $foreignStopId,
        TransportDirection::Pickup,
        Carbon::parse('2026-09-10'),
        p10TransportActor($user),
    );
})->throws(DomainException::class, 'does not belong');

it('rejects allocation onto an inactive route', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user, ['is_active' => false]);
    $stopId = p10TransportStopIds($route)[0];

    app(AllocateTransport::class)->handle(
        p10TransportEnrollmentId(),
        (int) $route->getKey(),
        $stopId,
        TransportDirection::Both,
        Carbon::parse('2026-09-10'),
        p10TransportActor($user),
    );
})->throws(DomainException::class, 'inactive');

it('ends the prior active allocation atomically on re-allocation', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stops = p10TransportStopIds($route);
    $enrollmentId = p10TransportEnrollmentId();

    $first = app(AllocateTransport::class)->handle(
        $enrollmentId,
        (int) $route->getKey(),
        $stops[0],
        TransportDirection::Both,
        Carbon::parse('2026-09-10'),
        p10TransportActor($user),
    );

    $second = app(AllocateTransport::class)->handle(
        $enrollmentId,
        (int) $route->getKey(),
        $stops[1],
        TransportDirection::Both,
        Carbon::parse('2026-10-01'),
        p10TransportActor($user),
    );

    $first->refresh();

    expect($first->status)->toBe(AllocationStatus::Ended)
        ->and($first->ends_on?->toDateString())->toBe('2026-09-30')
        ->and($second->status)->toBe(AllocationStatus::Active)
        ->and(TransportAllocation::query()->where('enrollment_id', $enrollmentId)->active()->count())->toBe(1);
});

it('clamps the prior end date on a same-day swap instead of violating the CHECK', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stops = p10TransportStopIds($route);
    $enrollmentId = p10TransportEnrollmentId();

    $first = app(AllocateTransport::class)->handle(
        $enrollmentId, (int) $route->getKey(), $stops[0],
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );

    app(AllocateTransport::class)->handle(
        $enrollmentId, (int) $route->getKey(), $stops[1],
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );

    $first->refresh();

    expect($first->status)->toBe(AllocationStatus::Ended)
        ->and($first->ends_on?->toDateString())->toBe('2026-09-10');
});

it('enforces one active allocation per enrollment at the DB layer', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stops = p10TransportStopIds($route);
    $enrollmentId = p10TransportEnrollmentId();

    app(AllocateTransport::class)->handle(
        $enrollmentId, (int) $route->getKey(), $stops[0],
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );

    // Bypass the Action entirely: the schema itself must refuse a second
    // ACTIVE row (NULL-unique generated active_key).
    DB::table('transport_allocations')->insert([
        'enrollment_id' => $enrollmentId,
        'route_id' => $route->getKey(),
        'stop_id' => $stops[1],
        'direction' => 'both',
        'starts_on' => '2026-11-01',
        'status' => 'active',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

it('permits any number of ENDED rows for the same enrollment', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stops = p10TransportStopIds($route);
    $enrollmentId = p10TransportEnrollmentId();

    foreach (['2026-09-10', '2026-10-01', '2026-11-01'] as $start) {
        app(AllocateTransport::class)->handle(
            $enrollmentId, (int) $route->getKey(), $stops[0],
            TransportDirection::Both, Carbon::parse($start), p10TransportActor($user),
        );
    }

    expect(TransportAllocation::query()->where('enrollment_id', $enrollmentId)->count())->toBe(3)
        ->and(TransportAllocation::query()->where('enrollment_id', $enrollmentId)->active()->count())->toBe(1);
});

// ── Ending ──────────────────────────────────────────────────────────────

it('ends an active allocation and refuses a double end', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stopId = p10TransportStopIds($route)[0];
    $enrollmentId = p10TransportEnrollmentId();

    $allocation = app(AllocateTransport::class)->handle(
        $enrollmentId, (int) $route->getKey(), $stopId,
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );

    $ended = app(EndTransportAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-12-15'), p10TransportActor($user),
    );

    expect($ended->status)->toBe(AllocationStatus::Ended)
        ->and($ended->ends_on?->toDateString())->toBe('2026-12-15')
        ->and(DB::table('transport_allocations')->where('id', $allocation->getKey())->value('active_key'))->toBeNull();

    expect(fn () => app(EndTransportAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-12-16'), p10TransportActor($user),
    ))->toThrow(DomainException::class, 'ACTIVE');
});

it('refuses an end date before the start date', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);
    $stopId = p10TransportStopIds($route)[0];

    $allocation = app(AllocateTransport::class)->handle(
        p10TransportEnrollmentId(), (int) $route->getKey(), $stopId,
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );

    app(EndTransportAllocation::class)->handle(
        (int) $allocation->getKey(), Carbon::parse('2026-09-01'), p10TransportActor($user),
    );
})->throws(DomainException::class, 'cannot end before it starts');

// ── Roster report ───────────────────────────────────────────────────────

it('lists active riders per route in stop order with student identity', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user, ['code' => 'RTA001']);
    $stops = p10TransportStopIds($route);

    $enrollmentA = p10TransportEnrollmentId();
    $enrollmentB = p10TransportEnrollmentId();

    // B boards at stop 1, A at stop 3 - the roster must come back in stop
    // sequence, not insertion, order.
    app(AllocateTransport::class)->handle(
        $enrollmentA, (int) $route->getKey(), $stops[2],
        TransportDirection::Both, Carbon::parse('2026-09-10'), p10TransportActor($user),
    );
    app(AllocateTransport::class)->handle(
        $enrollmentB, (int) $route->getKey(), $stops[0],
        TransportDirection::Pickup, Carbon::parse('2026-09-11'), p10TransportActor($user),
    );

    $roster = app(TransportRosterReport::class)->handle((int) $route->getKey());

    expect($roster)->toHaveCount(2)
        ->and($roster[0]['enrollment_id'])->toBe($enrollmentB)
        ->and($roster[0]['stop_sequence'])->toBe(1)
        ->and($roster[0]['direction'])->toBe('pickup')
        ->and($roster[1]['enrollment_id'])->toBe($enrollmentA)
        ->and($roster[1]['stop_sequence'])->toBe(3)
        ->and($roster[0]['route_code'])->toBe('RTA001')
        ->and($roster[0]['matricule'])->not->toBe('')
        ->and($roster[0]['student_name'])->toContain('Student');

    // Ended allocations drop off the roster.
    $active = TransportAllocation::query()->where('enrollment_id', $enrollmentA)->active()->firstOrFail();
    app(EndTransportAllocation::class)->handle(
        (int) $active->getKey(), Carbon::parse('2026-12-01'), p10TransportActor($user),
    );

    expect(app(TransportRosterReport::class)->handle((int) $route->getKey()))->toHaveCount(1);
});

it('refuses the roster without transport.view', function () {
    p10TransportUser(); // signed in, no abilities

    app(TransportRosterReport::class)->handle(null);
})->throws(AuthorizationException::class);
