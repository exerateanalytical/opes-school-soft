<?php

declare(strict_types=1);

use App\Modules\Identity\Models\User;
use App\Modules\Welfare\Actions\CreateRoute;
use App\Modules\Welfare\Actions\SaveVehicle;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Models\TransportRoute;
use App\Modules\Welfare\Models\Vehicle;
use App\Support\Audit\Actor;
use Database\Factories\EnrollmentFactory;

use function Pest\Laravel\actingAs;

/**
 * Shared fixtures for the Phase 10 W1 transport suites. Prefix
 * p10Transport, every helper function_exists-guarded (00-core test
 * discipline; names must never collide with another agent's).
 */
if (! function_exists('p10TransportUser')) {
    /** A signed-in user holding exactly the named abilities. */
    function p10TransportUser(string ...$permissions): User
    {
        $user = User::factory()->create();

        foreach ($permissions as $permission) {
            \Spatie\Permission\Models\Permission::findOrCreate($permission, 'web');
            $user->givePermissionTo($permission);
        }

        $user = $user->fresh() ?? $user;
        actingAs($user);

        return $user;
    }
}

if (! function_exists('p10TransportManager')) {
    /** The usual operator: holds both transport abilities. */
    function p10TransportManager(): User
    {
        return p10TransportUser(TransportPermission::VIEW, TransportPermission::MANAGE);
    }
}

if (! function_exists('p10TransportActor')) {
    function p10TransportActor(User $user): Actor
    {
        return $user->toAuditActor();
    }
}

if (! function_exists('p10TransportRoute')) {
    /**
     * A route with three ordered stops, created through the REAL gate.
     *
     * @param  array<string, mixed>  $overrides
     */
    function p10TransportRoute(User $user, array $overrides = []): TransportRoute
    {
        return app(CreateRoute::class)->handle(null, [
            'code' => 'RT'.fake()->unique()->numberBetween(1, 999_999),
            'name' => 'Route '.fake()->unique()->numberBetween(1, 999_999),
            'is_active' => true,
            'stops' => [
                ['name' => 'Downtown', 'pickup_time' => '06:45', 'dropoff_time' => '15:30'],
                ['name' => 'New Town', 'pickup_time' => '07:00', 'dropoff_time' => '15:45'],
                ['name' => 'School Gate', 'pickup_time' => '07:20', 'dropoff_time' => '16:00'],
            ],
            ...$overrides,
        ], p10TransportActor($user));
    }
}

if (! function_exists('p10TransportVehicle')) {
    /**
     * @param  array<string, mixed>  $overrides
     */
    function p10TransportVehicle(User $user, array $overrides = []): Vehicle
    {
        return app(SaveVehicle::class)->handle(null, [
            'registration_no' => 'ABC-'.fake()->unique()->numberBetween(100, 999_999),
            'make' => 'Toyota',
            'model' => 'Coaster',
            'capacity' => 30,
            ...$overrides,
        ], p10TransportActor($user));
    }
}

if (! function_exists('p10TransportStopIds')) {
    /**
     * The route's stop ids in sequence order, as a plain list - indexable
     * without the Collection::offsetGet nullability dance.
     *
     * @return list<int>
     */
    function p10TransportStopIds(TransportRoute $route): array
    {
        $ids = [];

        foreach ($route->stops()->orderBy('sequence')->pluck('id') as $id) {
            $ids[] = (int) $id;
        }

        return $ids;
    }
}

if (! function_exists('p10TransportEnrollmentId')) {
    /** An ACTIVE enrollment (the year-scoped student identity). */
    function p10TransportEnrollmentId(): int
    {
        $enrollment = EnrollmentFactory::new()->createOne();

        return (int) $enrollment->getKey();
    }
}

if (! function_exists('p10TransportWithdrawnEnrollmentId')) {
    function p10TransportWithdrawnEnrollmentId(): int
    {
        $enrollment = EnrollmentFactory::new()->withdrawn()->createOne();

        return (int) $enrollment->getKey();
    }
}
