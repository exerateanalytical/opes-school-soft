<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\CreateRoute;
use App\Modules\Welfare\Actions\SaveVehicle;
use App\Modules\Welfare\Domain\TransportPermission;
use App\Modules\Welfare\Livewire\Transport\Index as TransportIndex;
use App\Modules\Welfare\Models\VehicleDriver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

require_once __DIR__.'/TransportTestHelpers.php';

uses(RefreshDatabase::class);

// ── Routes and stops ────────────────────────────────────────────────────

it('creates a route with ordered stops through the gate', function () {
    $user = p10TransportManager();

    $route = p10TransportRoute($user, ['code' => 'RTA001', 'name' => 'Route A - Downtown']);

    expect($route->exists)->toBeTrue()
        ->and($route->code)->toBe('RTA001')
        ->and($route->is_active)->toBeTrue();

    $stops = $route->stops()->get()->all();

    // Re-sequenced 1..n in the order given, times kept.
    expect($stops)->toHaveCount(3)
        ->and($stops[0]->sequence)->toBe(1)
        ->and($stops[0]->name)->toBe('Downtown')
        ->and((string) $stops[0]->pickup_time)->toStartWith('06:45')
        ->and($stops[2]->sequence)->toBe(3)
        ->and($stops[2]->name)->toBe('School Gate');
});

it('refuses route creation without transport.manage', function () {
    p10TransportUser(TransportPermission::VIEW); // signed in, view only

    app(CreateRoute::class)->handle(null, [
        'code' => 'RTX001',
        'name' => 'Route X',
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);

it('rejects a duplicate route code', function () {
    $user = p10TransportManager();
    p10TransportRoute($user, ['code' => 'RTB002']);

    expect(fn () => p10TransportRoute($user, ['code' => 'RTB002']))
        ->toThrow(ValidationException::class);
});

it('updates a route in place and can deactivate it', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user, ['name' => 'Route C - Airport Road']);

    $updated = app(CreateRoute::class)->handle((int) $route->getKey(), [
        'name' => 'Route C - Airport Road (revised)',
        'is_active' => false,
    ], p10TransportActor($user));

    expect($updated->name)->toBe('Route C - Airport Road (revised)')
        ->and($updated->is_active)->toBeFalse()
        // Stops untouched when the payload omits them.
        ->and($updated->stops()->count())->toBe(3);
});

it('enforces stop sequence uniqueness per route at the DB layer', function () {
    $user = p10TransportManager();
    $route = p10TransportRoute($user);

    DB::table('transport_stops')->insert([
        'route_id' => $route->getKey(),
        'name' => 'Duplicate sequence',
        'sequence' => 1, // already taken by the first stop
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

// ── Vehicles ────────────────────────────────────────────────────────────

it('creates and updates a vehicle through the gate', function () {
    $user = p10TransportManager();

    $vehicle = p10TransportVehicle($user, [
        'registration_no' => 'ABC-123',
        'capacity' => 50,
        'insurance_expires_on' => '2027-01-15',
    ]);

    expect($vehicle->registration_no)->toBe('ABC-123')
        ->and($vehicle->capacity)->toBe(50)
        ->and($vehicle->status->value)->toBe('operational');

    $updated = app(SaveVehicle::class)->handle((int) $vehicle->getKey(), [
        'status' => 'under_maintenance',
    ], p10TransportActor($user));

    expect($updated->status->value)->toBe('under_maintenance');
});

it('refuses vehicle creation without transport.manage', function () {
    p10TransportUser(); // signed in, no abilities

    app(SaveVehicle::class)->handle(null, [
        'registration_no' => 'NOPE-1',
        'capacity' => 10,
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);

it('rejects a duplicate registration number and a zero capacity', function () {
    $user = p10TransportManager();
    p10TransportVehicle($user, ['registration_no' => 'ABC-124']);

    expect(fn () => p10TransportVehicle($user, ['registration_no' => 'ABC-124']))
        ->toThrow(ValidationException::class);

    expect(fn () => p10TransportVehicle($user, ['capacity' => 0]))
        ->toThrow(ValidationException::class);

    expect(fn () => p10TransportVehicle($user, ['status' => 'flying']))
        ->toThrow(ValidationException::class);
});

it('rejects an asset link that does not exist in the register', function () {
    $user = p10TransportManager();

    expect(fn () => p10TransportVehicle($user, ['asset_id' => 999_999]))
        ->toThrow(ValidationException::class);
});

it('stores driver licence numbers encrypted at rest', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    $driver = VehicleDriver::query()->create([
        'vehicle_id' => $vehicle->getKey(),
        'name' => 'John Nkwenti',
        'licence_no' => 'CM-DL-772431',
        'active_from' => '2026-09-01',
    ]);

    $raw = (string) DB::table('vehicle_drivers')
        ->where('id', $driver->getKey())
        ->value('licence_no');

    // Health-data discipline applied to identity data: the raw column never
    // carries the plaintext, and the model round-trips it.
    expect($raw)->not->toContain('CM-DL-772431')
        ->and($driver->refresh()->licence_no)->toBe('CM-DL-772431');
});

// ── The Transport Management screen ─────────────────────────────────────

it('renders the transport screen for a transport.view holder', function () {
    $user = p10TransportManager();
    p10TransportRoute($user, ['code' => 'RTA001', 'name' => 'Route A - Downtown']);
    p10TransportVehicle($user, ['registration_no' => 'ABC-125']);

    Livewire::test(TransportIndex::class)
        ->assertSee('Transport Management')
        ->assertSee('Total Buses')
        ->assertSee('Active Routes')
        ->assertSee('Route A - Downtown')
        ->assertSee('RTA001')
        ->assertSee('Vehicle Status');
});

it('switches tabs and rejects an unknown tab name', function () {
    $user = p10TransportManager();
    p10TransportVehicle($user, ['registration_no' => 'ABC-126', 'make' => 'Hyundai', 'model' => 'County']);

    Livewire::test(TransportIndex::class)
        ->call('selectTab', 'vehicles')
        ->assertSet('tab', 'vehicles')
        ->assertSee('ABC-126')
        ->assertSee('Hyundai County')
        ->call('selectTab', 'submarines')
        ->assertSet('tab', 'routes');
});

it('forbids the transport screen without transport.view', function () {
    p10TransportUser(); // signed in, no abilities

    Livewire::test(TransportIndex::class)->assertForbidden();
});
