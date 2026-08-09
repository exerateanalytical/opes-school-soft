<?php

declare(strict_types=1);

use App\Modules\Welfare\Actions\RecordFuelLog;
use App\Modules\Welfare\Actions\RecordMaintenanceLog;
use App\Modules\Welfare\Actions\RecordTripLog;
use App\Modules\Welfare\Models\VehicleDriver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require_once __DIR__.'/TransportTestHelpers.php';

uses(RefreshDatabase::class);

// ── Trip logs ───────────────────────────────────────────────────────────

it('records a trip log with driver and route', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);
    $route = p10TransportRoute($user);

    $driver = VehicleDriver::query()->create([
        'vehicle_id' => $vehicle->getKey(),
        'name' => 'Mary Njoh',
        'active_from' => '2026-09-01',
    ]);

    $log = app(RecordTripLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'route_id' => (int) $route->getKey(),
        'driver_id' => (int) $driver->getKey(),
        'date' => '2026-09-10',
        'odometer_start' => 45_000,
        'odometer_end' => 45_032,
        'notes' => 'Morning run',
    ], p10TransportActor($user));

    expect($log->exists)->toBeTrue()
        ->and($log->odometer_start)->toBe(45_000)
        ->and($log->odometer_end)->toBe(45_032)
        ->and($log->driver_id)->toBe((int) $driver->getKey());
});

it('rejects an odometer that runs backwards within the trip', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    app(RecordTripLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'odometer_start' => 45_032,
        'odometer_end' => 45_000,
    ], p10TransportActor($user));
})->throws(ValidationException::class);

it('rejects a trip starting behind the furthest logged reading', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    app(RecordTripLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'odometer_start' => 45_000,
        'odometer_end' => 45_050,
    ], p10TransportActor($user));

    app(RecordTripLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-11',
        'odometer_start' => 44_900, // behind 45_050
        'odometer_end' => 45_060,
    ], p10TransportActor($user));
})->throws(ValidationException::class);

it('rejects a driver who is not assigned to the vehicle', function () {
    $user = p10TransportManager();
    $vehicleA = p10TransportVehicle($user);
    $vehicleB = p10TransportVehicle($user);

    $foreignDriver = VehicleDriver::query()->create([
        'vehicle_id' => $vehicleB->getKey(),
        'name' => 'Peter Mbarga',
        'active_from' => '2026-09-01',
    ]);

    app(RecordTripLog::class)->handle([
        'vehicle_id' => (int) $vehicleA->getKey(),
        'driver_id' => (int) $foreignDriver->getKey(),
        'date' => '2026-09-10',
        'odometer_start' => 10,
        'odometer_end' => 20,
    ], p10TransportActor($user));
})->throws(ValidationException::class);

it('backs the odometer rule with a DB CHECK', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    DB::table('vehicle_trip_logs')->insert([
        'vehicle_id' => $vehicle->getKey(),
        'date' => '2026-09-10',
        'odometer_start' => 500,
        'odometer_end' => 400,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
})->throws(QueryException::class);

// ── Fuel logs ───────────────────────────────────────────────────────────

it('records a fuel purchase as an operational record only', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    $ledgerBefore = (int) DB::table('journal_entries')->count();

    $log = app(RecordFuelLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'litres' => '65.50',
        'cost_amount' => 55_000,
        'odometer' => 45_100,
    ], p10TransportActor($user));

    expect((float) $log->litres)->toBe(65.5)
        ->and($log->cost_amount)->toBe(55_000)
        // NO ledger writes in Phase 10: the cost is informational; the
        // payable posts through the Phase 5 supplier invoice flow.
        ->and((int) DB::table('journal_entries')->count())->toBe($ledgerBefore);
});

it('rejects non-positive litres and negative fuel cost', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    expect(fn () => app(RecordFuelLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'litres' => '0',
        'cost_amount' => 1_000,
    ], p10TransportActor($user)))->toThrow(ValidationException::class);

    expect(fn () => app(RecordFuelLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'litres' => '10',
        'cost_amount' => -5,
    ], p10TransportActor($user)))->toThrow(ValidationException::class);
});

// ── Maintenance logs ────────────────────────────────────────────────────

it('records maintenance work with type and cost', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    $log = app(RecordMaintenanceLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-12',
        'type' => 'repair',
        'description' => 'Brake pads replaced',
        'cost_amount' => 120_000,
    ], p10TransportActor($user));

    expect($log->type->value)->toBe('repair')
        ->and($log->cost_amount)->toBe(120_000)
        ->and($log->supplier_id)->toBeNull();
});

it('rejects an unknown type, a blank description, a negative cost and a ghost supplier', function () {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    $base = [
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-12',
        'type' => 'service',
        'description' => 'Oil change',
    ];

    expect(fn () => app(RecordMaintenanceLog::class)->handle(
        ['type' => 'voodoo'] + $base, p10TransportActor($user)
    ))->toThrow(ValidationException::class);

    expect(fn () => app(RecordMaintenanceLog::class)->handle(
        ['description' => '  '] + $base, p10TransportActor($user)
    ))->toThrow(ValidationException::class);

    expect(fn () => app(RecordMaintenanceLog::class)->handle(
        ['cost_amount' => -1] + $base, p10TransportActor($user)
    ))->toThrow(ValidationException::class);

    expect(fn () => app(RecordMaintenanceLog::class)->handle(
        ['supplier_id' => 999_999] + $base, p10TransportActor($user)
    ))->toThrow(ValidationException::class);
});

it('refuses log recording without transport.manage', function () {
    $manager = p10TransportManager();
    $vehicle = p10TransportVehicle($manager);

    p10TransportUser(); // now signed in with no abilities

    app(RecordFuelLog::class)->handle([
        'vehicle_id' => (int) $vehicle->getKey(),
        'date' => '2026-09-10',
        'litres' => '10',
        'cost_amount' => 1_000,
    ], \App\Support\Audit\Actor::system());
})->throws(AuthorizationException::class);
