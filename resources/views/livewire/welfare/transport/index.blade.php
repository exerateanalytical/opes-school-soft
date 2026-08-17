@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $vehicleTone = [
        'operational' => 'ok',
        'under_maintenance' => 'amber',
        'out_of_service' => 'red',
    ];

    $vehicleLabel = [
        'operational' => 'Operational',
        'under_maintenance' => 'Under maintenance',
        'out_of_service' => 'Out of service',
    ];

    $tabs = [
        ['value' => 'routes', 'label' => 'Routes & Stops', 'count' => $tabCounts['routes']],
        ['value' => 'vehicles', 'label' => 'Vehicles', 'count' => $tabCounts['vehicles']],
        ['value' => 'allocations', 'label' => 'Allocations', 'count' => $tabCounts['allocations']],
        ['value' => 'logs', 'label' => 'Logs', 'count' => $tabCounts['logs']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Inline "Add Route" panel. --}}
    @if ($showRouteForm)
        <section aria-label="Add route" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Add Route</h2>

            <form wire:submit="saveRoute" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="route-form-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Route code</span>
                        <input id="route-form-code" type="text" wire:model="routeFormCode"
                               placeholder="e.g. R-01"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('routeFormCode')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="route-form-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Route name</span>
                        <input id="route-form-name" type="text" wire:model="routeFormName"
                               placeholder="e.g. Town - Mile 4"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('routeFormName')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="route-form-active" class="flex items-center gap-2 sm:col-span-2">
                        <input id="route-form-active" type="checkbox" wire:model="routeFormActive"
                               class="rounded border-border-primary text-primary focus:ring-primary/50"/>
                        <span class="text-sm text-charcoal/80">Active (accepts riders)</span>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save route
                    </button>
                    <button type="button" wire:click="toggleRouteForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline "Allocate Student" panel. --}}
    @if ($showAllocationForm)
        <section aria-label="Allocate student to route" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Allocate Student</h2>

            <form wire:submit="saveAllocation" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="allocation-form-enrollment" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Enrollment ID</span>
                        <input id="allocation-form-enrollment" type="number" min="1" wire:model="allocationFormEnrollmentId"
                               placeholder="e.g. 1024"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('allocationFormEnrollmentId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="allocation-form-route" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Route</span>
                        <select id="allocation-form-route" wire:model="allocationFormRouteId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a route</option>
                            @foreach ($routeOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('allocationFormRouteId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="allocation-form-stop" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Stop</span>
                        <select id="allocation-form-stop" wire:model="allocationFormStopId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a stop</option>
                            @foreach ($stopOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('allocationFormStopId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="allocation-form-direction" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Direction</span>
                        <select id="allocation-form-direction" wire:model="allocationFormDirection"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="both">Both</option>
                            <option value="pickup">Pickup only</option>
                            <option value="dropoff">Dropoff only</option>
                        </select>
                    </label>

                    <label for="allocation-form-starts-on" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Starts on</span>
                        <input id="allocation-form-starts-on" type="date" wire:model="allocationFormStartsOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('allocationFormStartsOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Allocate
                    </button>
                    <button type="button" wire:click="toggleAllocationForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline "Add Vehicle" panel. --}}
    @if ($showVehicleForm)
        <section aria-label="Add vehicle" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Add Vehicle</h2>

            <form wire:submit="saveVehicle" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="vehicle-form-registration" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Registration number</span>
                        <input id="vehicle-form-registration" type="text" wire:model="vehicleFormRegistrationNo"
                               placeholder="e.g. LT 1234 A"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormRegistrationNo')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-capacity" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Capacity</span>
                        <input id="vehicle-form-capacity" type="number" min="1" wire:model="vehicleFormCapacity"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormCapacity')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-make" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Make</span>
                        <input id="vehicle-form-make" type="text" wire:model="vehicleFormMake"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormMake')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-model" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Model</span>
                        <input id="vehicle-form-model" type="text" wire:model="vehicleFormModel"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormModel')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-status" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Status</span>
                        <select id="vehicle-form-status" wire:model="vehicleFormStatus"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="operational">Operational</option>
                            <option value="under_maintenance">Under maintenance</option>
                            <option value="out_of_service">Out of service</option>
                        </select>
                        @error('vehicleFormStatus')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-insurance-expires" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.welfare_detail.insurance_expires_on') }}</span>
                        <input id="vehicle-form-insurance-expires" type="date" wire:model="vehicleFormInsuranceExpiresOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormInsuranceExpiresOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="vehicle-form-inspection-expires" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.welfare_detail.inspection_expires_on') }}</span>
                        <input id="vehicle-form-inspection-expires" type="date" wire:model="vehicleFormInspectionExpiresOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('vehicleFormInspectionExpiresOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save vehicle
                    </button>
                    <button type="button" wire:click="toggleVehicleForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline "Record Trip" panel (Logs tab, trips only). --}}
    @if ($showTripLogForm && $tab === 'logs' && $logType === 'trips')
        <section aria-label="Record trip log" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Record Trip</h2>

            <form wire:submit="saveTripLog" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="trip-log-form-vehicle" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Vehicle</span>
                        <select id="trip-log-form-vehicle" wire:model="tripLogFormVehicleId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a vehicle</option>
                            @foreach ($vehicleOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('tripLogFormVehicleId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-route" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Route (optional)</span>
                        <select id="trip-log-form-route" wire:model="tripLogFormRouteId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">No route</option>
                            @foreach ($routeOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('tripLogFormRouteId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-driver" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Driver ID (optional)</span>
                        <input id="trip-log-form-driver" type="number" min="1" wire:model="tripLogFormDriverId"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('tripLogFormDriverId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Date</span>
                        <input id="trip-log-form-date" type="date" wire:model="tripLogFormDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('tripLogFormDate')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-odometer-start" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Odometer start</span>
                        <input id="trip-log-form-odometer-start" type="number" min="0" wire:model="tripLogFormOdometerStart"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('tripLogFormOdometerStart')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-odometer-end" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Odometer end</span>
                        <input id="trip-log-form-odometer-end" type="number" min="0" wire:model="tripLogFormOdometerEnd"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('tripLogFormOdometerEnd')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="trip-log-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Notes</span>
                        <input id="trip-log-form-notes" type="text" wire:model="tripLogFormNotes"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('tripLogFormNotes')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save trip
                    </button>
                    <button type="button" wire:click="toggleTripLogForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline "Record Fuel" panel (Logs tab, fuel only). --}}
    @if ($showFuelLogForm && $tab === 'logs' && $logType === 'fuel')
        <section aria-label="Record fuel log" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Record Fuel</h2>

            <form wire:submit="saveFuelLog" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="fuel-log-form-vehicle" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Vehicle</span>
                        <select id="fuel-log-form-vehicle" wire:model="fuelLogFormVehicleId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a vehicle</option>
                            @foreach ($vehicleOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('fuelLogFormVehicleId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="fuel-log-form-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Date</span>
                        <input id="fuel-log-form-date" type="date" wire:model="fuelLogFormDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fuelLogFormDate')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="fuel-log-form-litres" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Litres</span>
                        <input id="fuel-log-form-litres" type="number" step="0.01" min="0.01" wire:model="fuelLogFormLitres"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fuelLogFormLitres')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="fuel-log-form-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Cost (XAF)</span>
                        <input id="fuel-log-form-cost" type="number" min="0" wire:model="fuelLogFormCostAmount"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fuelLogFormCostAmount')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="fuel-log-form-odometer" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Odometer (optional)</span>
                        <input id="fuel-log-form-odometer" type="number" min="0" wire:model="fuelLogFormOdometer"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fuelLogFormOdometer')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save fuel log
                    </button>
                    <button type="button" wire:click="toggleFuelLogForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline "Record Maintenance" panel (Logs tab, maintenance only). --}}
    @if ($showMaintenanceLogForm && $tab === 'logs' && $logType === 'maintenance')
        <section aria-label="Record maintenance log" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Record Maintenance</h2>

            <form wire:submit="saveMaintenanceLog" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="maintenance-log-form-vehicle" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Vehicle</span>
                        <select id="maintenance-log-form-vehicle" wire:model="maintenanceLogFormVehicleId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a vehicle</option>
                            @foreach ($vehicleOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('maintenanceLogFormVehicleId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-log-form-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Type</span>
                        <select id="maintenance-log-form-type" wire:model="maintenanceLogFormType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="service">Service</option>
                            <option value="repair">Repair</option>
                            <option value="inspection">Inspection</option>
                            <option value="other">Other</option>
                        </select>
                        @error('maintenanceLogFormType')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-log-form-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Date</span>
                        <input id="maintenance-log-form-date" type="date" wire:model="maintenanceLogFormDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('maintenanceLogFormDate')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-log-form-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Cost (XAF, optional)</span>
                        <input id="maintenance-log-form-cost" type="number" min="0" wire:model="maintenanceLogFormCostAmount"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('maintenanceLogFormCostAmount')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-log-form-description" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Description</span>
                        <input id="maintenance-log-form-description" type="text" wire:model="maintenanceLogFormDescription"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('maintenanceLogFormDescription')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save maintenance log
                    </button>
                    <button type="button" wire:click="toggleMaintenanceLogForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

<x-list-screen
    title="Transport Management"
    :breadcrumb="['Dashboard', 'Transport']"
    :paginator="$rows"
    empty-message="No transport records match these filters yet. Routes, vehicles and allocations appear here as they are set up."
    rail-title="Transport Overview"
>
    <x-slot:actions>
        @if ($canManage)
        @if ($tab === 'routes')
            <button type="button" wire:click="toggleRouteForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showRouteForm ? 'Hide form' : 'Add route' }}
            </button>
        @elseif ($tab === 'vehicles')
            <button type="button" wire:click="toggleVehicleForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showVehicleForm ? 'Hide form' : 'Add vehicle' }}
            </button>
        @elseif ($tab === 'allocations')
            <button type="button" wire:click="toggleAllocationForm"
                    class="rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                {{ $showAllocationForm ? 'Hide form' : 'Allocate student' }}
            </button>
        @elseif ($tab === 'logs' && $logType === 'trips')
            <button type="button" wire:click="toggleTripLogForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showTripLogForm ? 'Hide form' : 'Record trip' }}
            </button>
        @elseif ($tab === 'logs' && $logType === 'fuel')
            <button type="button" wire:click="toggleFuelLogForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showFuelLogForm ? 'Hide form' : 'Record fuel' }}
            </button>
        @elseif ($tab === 'logs' && $logType === 'maintenance')
            <button type="button" wire:click="toggleMaintenanceLogForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showMaintenanceLogForm ? 'Hide form' : 'Record maintenance' }}
            </button>
        @endif
        @endif
    </x-slot:actions>

    {{-- Five KPI cards, mirroring the mockup's strip: buses, routes,
         riders, trips, maintenance due - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Buses" :value="$kpis['buses']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="3" width="16" height="14" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 21v-4M16 21v-4"/><circle cx="8.5" cy="13.5" r="0.5"/><circle cx="15.5" cy="13.5" r="0.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Active Routes" :value="$kpis['active_routes']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6-5.2-6-10a6 6 0 1112 0c0 4.8-6 10-6 10z"/><circle cx="12" cy="11" r="2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Students Transported" :value="$kpis['students']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Trips Logged" :value="$kpis['trips']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Maintenance Due" :value="$kpis['maintenance_due']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 005.4-5.4l-2.4 2.4-2.3-2.3 2.4-2.4z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="transport-filter-route" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Route</span>
            <select id="transport-filter-route" wire:model.live="route"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All routes</option>
                @foreach ($routeOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="transport-filter-vehicle" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Vehicle</span>
            <select id="transport-filter-vehicle" wire:model.live="vehicle"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All vehicles</option>
                @foreach ($vehicleOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="transport-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="transport-filter-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($tab === 'logs')
            <label for="transport-filter-logtype" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Log type</span>
                <select id="transport-filter-logtype" wire:model.live="logType"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="trips">Trips</option>
                    <option value="fuel">Fuel</option>
                    <option value="maintenance">Maintenance</option>
                </select>
            </label>
        @endif

        <label for="transport-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="transport-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search route, vehicle, student..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'routes')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route Code</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Stops</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Students</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Trip Time</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'vehicles')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Make / Model</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Driver</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Capacity</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Insurance</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Inspection</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($tab === 'allocations')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Stop</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Direction</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Since</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($logType === 'fuel')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Litres</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Cost</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Odometer</th>
            @elseif ($logType === 'maintenance')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Description</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Cost</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Vehicle</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Route</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Driver</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Odometer</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Distance</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="transport-{{ $tab }}-{{ $logType }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'routes')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->code }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->stops_count }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->riders_count }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->first_pickup !== null ? substr((string) $row->first_pickup, 0, 5) : '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                </td>
            @elseif ($tab === 'vehicles')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ trim(($row->make ?? '').' '.($row->model ?? '')) !== '' ? trim(($row->make ?? '').' '.($row->model ?? '')) : '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->driver_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->capacity }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->insurance_expires_on ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->inspection_expires_on ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$vehicleTone[$row->status] ?? 'ok'" :label="$vehicleLabel[$row->status] ?? $row->status"/>
                </td>
                <td class="px-4 py-2.5">
                    <a href="{{ url('/transport/vehicles/'.$row->id) }}" class="text-sm font-medium text-primary hover:underline">View</a>
                </td>
            @elseif ($tab === 'allocations')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->route_code }} - {{ $row->route_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->stop_sequence }}. {{ $row->stop_name }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->direction }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                </td>
                <td class="px-4 py-2.5">
                    @if ($row->status === 'active' && $canManage)
                        <button type="button" wire:click="endAllocation({{ $row->id }})"
                                wire:confirm="End this transport allocation?"
                                class="rounded border border-heritage-red px-2.5 py-1 text-xs font-semibold text-heritage-red hover:bg-heritage-red/10">
                            End allocation
                        </button>
                    @endif
                </td>
            @elseif ($logType === 'fuel')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->litres }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->cost_amount)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->odometer ?? '—' }}</td>
            @elseif ($logType === 'maintenance')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->description }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->cost_amount !== null ? Money::of((int) $row->cost_amount)->format(false) : '—' }}</td>
            @else
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->date }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->registration_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->route_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->driver_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->odometer_start }} → {{ $row->odometer_end }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ (int) $row->odometer_end - (int) $row->odometer_start }} km</td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="transport-card-{{ $tab }}-{{ $logType }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'routes')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->name }}</p>
                        <x-status-pill :status="$row->is_active ? 'ok' : 'red'" :label="$row->is_active ? 'Active' : 'Inactive'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->code }} · {{ $row->stops_count }} stops · {{ $row->riders_count }} students</p>
                @elseif ($tab === 'vehicles')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->registration_no }}</p>
                        <x-status-pill :status="$vehicleTone[$row->status] ?? 'ok'" :label="$vehicleLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ trim(($row->make ?? '').' '.($row->model ?? '')) }} · {{ $row->capacity }} seats</p>
                @elseif ($tab === 'allocations')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Ended'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->route_code }} · {{ $row->stop_name }} · {{ $row->direction }}</p>
                    @if ($row->status === 'active' && $canManage)
                        <button type="button" wire:click="endAllocation({{ $row->id }})"
                                wire:confirm="End this transport allocation?"
                                class="mt-2 rounded border border-heritage-red px-2.5 py-1 text-xs font-semibold text-heritage-red hover:bg-heritage-red/10">
                            End allocation
                        </button>
                    @endif
                @elseif ($logType === 'fuel')
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->litres }} L · {{ Money::of((int) $row->cost_amount)->format(false) }}</p>
                @elseif ($logType === 'maintenance')
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm capitalize text-charcoal/70">{{ $row->type }} · {{ $row->description }}</p>
                @else
                    <p class="font-medium text-charcoal">{{ $row->registration_no }} · {{ $row->date }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->route_name ?? 'Off-route' }} · {{ (int) $row->odometer_end - (int) $row->odometer_start }} km</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: vehicle status bars + upcoming maintenance, as in the
         mockup's right column. --}}
    <x-slot:rail>
        <div class="space-y-4">
            <section aria-label="Vehicle status" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Vehicle Status</h3>
                <ul class="space-y-2.5">
                    @foreach ($vehicleStatusBreakdown as $band)
                        <li>
                            <div class="flex items-center justify-between text-xs text-charcoal/70">
                                <span>{{ $vehicleLabel[$band['status']] ?? $band['status'] }}</span>
                                <span class="tabular-nums">{{ $band['count'] }} ({{ $band['share'] }}%)</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                <div class="h-1.5 rounded-full {{ match ($band['status']) {
                                        'operational' => 'bg-primary',
                                        'under_maintenance' => 'bg-heritage-yellow',
                                        default => 'bg-heritage-red',
                                    } }}"
                                     style="width: {{ $band['share'] }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </section>

            <section aria-label="Upcoming maintenance" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Upcoming Maintenance</h3>
                @if ($upcomingMaintenance === [])
                    <p class="text-sm text-charcoal/60">Nothing due in the next 60 days.</p>
                @else
                    <ul class="space-y-2">
                        @foreach ($upcomingMaintenance as $item)
                            <li class="flex items-start justify-between gap-2 text-sm">
                                <div>
                                    <p class="font-medium text-charcoal">{{ $item['registration_no'] }}</p>
                                    <p class="text-xs text-charcoal/60">{{ $item['kind'] }}</p>
                                </div>
                                <span class="whitespace-nowrap text-xs font-semibold text-heritage-red">Due: {{ $item['due_on'] }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
</div>
