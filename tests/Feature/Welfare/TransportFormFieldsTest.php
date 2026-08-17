<?php

declare(strict_types=1);

use App\Modules\Welfare\Livewire\Transport\Index;
use App\Modules\Welfare\Models\Vehicle;
use App\Modules\Welfare\Models\VehicleMaintenanceLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;

require_once __DIR__.'/TransportTestHelpers.php';

uses(RefreshDatabase::class);

/*
 * Three gaps between the transport forms and the Actions behind them:
 *
 *  1. `vehicle_maintenance_logs.date` is NOT NULL and RecordMaintenanceLog
 *     reads it straight out of $data, but the form never collected it - so
 *     every "Save maintenance log" inserted NULL and died on the constraint.
 *  2. SaveVehicle accepts `insurance_expires_on` / `inspection_expires_on`
 *     (both on Vehicle::$fillable) and the Insurance and Inspection columns,
 *     the "Maintenance Due" KPI and the "Upcoming Maintenance" rail all read
 *     them - but no form ever set them, so all four were permanently blank.
 *  3. A transport.view-only user was shown manage buttons that 403 on click.
 */

it('records a maintenance log with the date the form now collects', function (): void {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    Livewire::test(Index::class)
        ->call('toggleMaintenanceLogForm')
        ->set('maintenanceLogFormVehicleId', (string) $vehicle->getKey())
        ->set('maintenanceLogFormType', 'service')
        ->set('maintenanceLogFormDate', '2026-03-04')
        ->set('maintenanceLogFormDescription', 'Full service, oil and filters')
        ->set('maintenanceLogFormCostAmount', '45000')
        ->call('saveMaintenanceLog')
        ->assertHasNoErrors();

    $log = VehicleMaintenanceLog::query()->firstOrFail();

    expect($log->date->toDateString())->toBe('2026-03-04')
        ->and($log->cost_amount)->toBe(45_000);
});

it('defaults the maintenance date to today when the panel opens', function (): void {
    p10TransportManager();

    Livewire::test(Index::class)
        ->call('toggleMaintenanceLogForm')
        ->assertSet('maintenanceLogFormDate', now()->format('Y-m-d'));
});

it('rejects a maintenance log with no date rather than hitting the constraint', function (): void {
    $user = p10TransportManager();
    $vehicle = p10TransportVehicle($user);

    Livewire::test(Index::class)
        ->call('toggleMaintenanceLogForm')
        ->set('maintenanceLogFormVehicleId', (string) $vehicle->getKey())
        ->set('maintenanceLogFormType', 'repair')
        ->set('maintenanceLogFormDate', '')
        ->set('maintenanceLogFormDescription', 'Gearbox')
        ->call('saveMaintenanceLog')
        ->assertHasErrors(['maintenanceLogFormDate']);

    expect(VehicleMaintenanceLog::query()->count())->toBe(0);
});

it('saves both compliance expiry dates from the add-vehicle form', function (): void {
    p10TransportManager();

    Livewire::test(Index::class)
        ->call('toggleVehicleForm')
        ->set('vehicleFormRegistrationNo', 'CE-4410-AB')
        ->set('vehicleFormMake', 'Toyota')
        ->set('vehicleFormModel', 'Coaster')
        ->set('vehicleFormCapacity', '30')
        ->set('vehicleFormStatus', 'operational')
        ->set('vehicleFormInsuranceExpiresOn', '2026-11-30')
        ->set('vehicleFormInspectionExpiresOn', '2026-09-15')
        ->call('saveVehicle')
        ->assertHasNoErrors();

    $vehicle = Vehicle::query()->where('registration_no', 'CE-4410-AB')->firstOrFail();

    expect($vehicle->insurance_expires_on?->toDateString())->toBe('2026-11-30')
        ->and($vehicle->inspection_expires_on?->toDateString())->toBe('2026-09-15');
});

it('leaves the expiry dates null when they are not supplied', function (): void {
    p10TransportManager();

    Livewire::test(Index::class)
        ->call('toggleVehicleForm')
        ->set('vehicleFormRegistrationNo', 'CE-4411-AB')
        ->set('vehicleFormCapacity', '18')
        ->set('vehicleFormStatus', 'operational')
        ->call('saveVehicle')
        ->assertHasNoErrors();

    $vehicle = Vehicle::query()->where('registration_no', 'CE-4411-AB')->firstOrFail();

    expect($vehicle->insurance_expires_on)->toBeNull()
        ->and($vehicle->inspection_expires_on)->toBeNull();
});

it('does not offer manage buttons to a view-only transport user', function (): void {
    p10TransportUser(App\Modules\Welfare\Domain\TransportPermission::VIEW);

    Livewire::test(Index::class)
        ->assertDontSee('Add route')
        ->assertDontSee('Add vehicle');
});

it('offers the manage buttons to a transport manager', function (): void {
    p10TransportManager();

    Livewire::test(Index::class)
        ->assertSee('Add route');
});
