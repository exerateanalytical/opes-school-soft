@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $assetTone = [
        'draft' => 'amber',
        'in_progress' => 'amber',
        'in_service' => 'ok',
        'idle' => 'amber',
        'under_maintenance' => 'amber',
        'impaired' => 'red',
        'disposed' => 'red',
        'written_off' => 'red',
        'lost' => 'red',
    ];

    $maintenanceTone = [
        'open' => 'amber',
        'assigned' => 'amber',
        'in_progress' => 'amber',
        'done' => 'ok',
        'cancelled' => 'red',
    ];

    $runTone = [
        'draft' => 'amber',
        'calculated' => 'amber',
        'approved' => 'ok',
        'posted' => 'ok',
        'cancelled' => 'red',
    ];

    $label = fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

    $tabs = [
        ['value' => 'assets', 'label' => 'Assets', 'count' => $tabCounts['assets']],
        ['value' => 'maintenance', 'label' => 'Maintenance', 'count' => $tabCounts['maintenance']],
        ['value' => 'depreciation', 'label' => 'Depreciation Runs', 'count' => $tabCounts['depreciation']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Inline register-asset panel (Assets register; no separate route). --}}
    @if ($showAssetForm)
        <section aria-label="Register asset" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Register Asset</h2>

            <form wire:submit="saveAsset" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="asset-form-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name</span>
                        <input id="asset-form-name" type="text" wire:model="assetFormName"
                               placeholder="e.g. Dell Latitude 5420"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('assetFormName')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="asset-form-tag" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Tag number (optional, auto-assigned if blank)</span>
                        <input id="asset-form-tag" type="text" wire:model="assetFormTagNumber"
                               placeholder="e.g. AST/000123"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="asset-form-category" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Category</span>
                        <select id="asset-form-category" wire:model="assetFormCategoryId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a category...</option>
                            @foreach ($categoryOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('assetFormCategoryId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="asset-form-serial" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Serial number</span>
                        <input id="asset-form-serial" type="text" wire:model="assetFormSerialNumber"
                               placeholder="Required for some categories"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="asset-form-acquisition-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Acquisition type</span>
                        <select id="asset-form-acquisition-type" wire:model="assetFormAcquisitionType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($acquisitionTypeOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="asset-form-acquisition-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Acquisition date</span>
                        <input id="asset-form-acquisition-date" type="date" wire:model="assetFormAcquisitionDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="asset-form-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Acquisition cost (XAF)</span>
                        <input id="asset-form-cost" type="number" min="0" step="1" wire:model="assetFormAcquisitionCost"
                               placeholder="e.g. 350000"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('assetFormAcquisitionCost')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="asset-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Notes (optional)</span>
                        <textarea id="asset-form-notes" wire:model="assetFormNotes" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Register asset
                    </button>
                    <button type="button" wire:click="toggleAssetForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline dispose-asset panel (opened from a row action below). --}}
    @if ($showDisposeForm)
        <section aria-label="Dispose asset" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Dispose Asset — {{ $disposeAssetLabel }}</h2>

            <form wire:submit="saveDisposeAsset" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="dispose-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal type</span>
                        <select id="dispose-type" wire:model="disposeType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($disposalTypeOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="dispose-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal date</span>
                        <input id="dispose-date" type="date" wire:model="disposeDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('disposeReason')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="dispose-proceeds" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Proceeds (XAF, optional)</span>
                        <input id="dispose-proceeds" type="number" min="0" step="1" wire:model="disposeProceeds"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="dispose-settlement" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Settlement route (required if proceeds &gt; 0)</span>
                        <select id="dispose-settlement" wire:model="disposeSettlement"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">None</option>
                            @foreach ($disposalSettlementOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="dispose-buyer" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Buyer partner ID (required for a sale)</span>
                        <input id="dispose-buyer" type="number" min="1" step="1" wire:model="disposeBuyerPartnerId"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="dispose-reason" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Reason</span>
                        <textarea id="dispose-reason" wire:model="disposeReason" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-heritage-red px-4 py-2 text-sm font-semibold text-white hover:bg-heritage-red/90">
                        Dispose asset
                    </button>
                    <button type="button" wire:click="toggleDisposeForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline run-depreciation panel. --}}
    @if ($showRunDepreciationForm)
        <section aria-label="Run depreciation" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Run Depreciation</h2>

            <form wire:submit="saveRunDepreciation" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="run-fiscal-year" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
                        <select id="run-fiscal-year" wire:model="runFiscalYearId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select an open fiscal year...</option>
                            @foreach ($fiscalYearOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('runFiscalYearId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="run-period-month" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Period (1-12)</span>
                        <input id="run-period-month" type="number" min="1" max="12" step="1" wire:model="runPeriodMonth"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('runPeriodMonth')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Calculate run
                    </button>
                    <button type="button" wire:click="toggleRunDepreciationForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline close-maintenance-request panel. --}}
    @if ($showCloseMaintenanceForm)
        <section aria-label="Close maintenance request" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Close Maintenance Request</h2>

            <form wire:submit="saveCloseMaintenanceRequest" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="close-maintenance-resolution" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Resolution</span>
                        <select id="close-maintenance-resolution" wire:model="closeMaintenanceResolution"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($maintenanceResolutionOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="close-maintenance-actual-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Actual cost (XAF, required if capitalising)</span>
                        <input id="close-maintenance-actual-cost" type="number" min="0" step="1" wire:model="closeMaintenanceActualCost"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="close-maintenance-capitalise-as" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Capitalise as (if capitalising)</span>
                        <select id="close-maintenance-capitalise-as" wire:model="closeMaintenanceCapitaliseAs"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="increase_cost">Increase cost</option>
                            <option value="component">New component</option>
                        </select>
                    </label>

                    <label for="close-maintenance-justification" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Justification</span>
                        <textarea id="close-maintenance-justification" wire:model="closeMaintenanceJustification" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('closeMaintenanceJustification')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Close request
                    </button>
                    <button type="button" wire:click="toggleCloseMaintenanceForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline create-asset-category panel. --}}
    @if ($showCategoryForm)
        <section aria-label="Create asset category" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">New Asset Category</h2>

            <form wire:submit="saveCategory" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="category-form-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Code</span>
                        <input id="category-form-code" type="text" wire:model="categoryFormCode"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('categoryFormCode')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="category-form-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (English)</span>
                        <input id="category-form-name" type="text" wire:model="categoryFormName"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="category-form-name-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (French)</span>
                        <input id="category-form-name-fr" type="text" wire:model="categoryFormNameFr"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="category-form-depreciation-method" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Depreciation method</span>
                        <select id="category-form-depreciation-method" wire:model="categoryFormDepreciationMethod"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($depreciationMethodOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="category-form-useful-life" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Useful life (months, required unless "none")</span>
                        <input id="category-form-useful-life" type="number" min="1" step="1" wire:model="categoryFormUsefulLifeMonths"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="category-form-declining-rate" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Declining rate (bp, required for declining balance)</span>
                        <input id="category-form-declining-rate" type="number" min="1" step="1" wire:model="categoryFormDecliningRateBp"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="category-form-prorata" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Prorata convention (required to run depreciation later)</span>
                        <select id="category-form-prorata" wire:model="categoryFormProrataConvention"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Not yet declared</option>
                            @foreach ($prorataConventionOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="category-form-asset-account" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Gross asset account (class 2)</span>
                        <select id="category-form-asset-account" wire:model="categoryFormAssetAccountId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select an account...</option>
                            @foreach ($accountOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="category-form-accumulated-account" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Accumulated depreciation account (class 28)</span>
                        <select id="category-form-accumulated-account" wire:model="categoryFormAccumulatedDepreciationAccountId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select an account...</option>
                            @foreach ($accountOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="category-form-disposal-nbv-account" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal NBV account (81x)</span>
                        <select id="category-form-disposal-nbv-account" wire:model="categoryFormDisposalNbvAccountId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select an account...</option>
                            @foreach ($accountOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="category-form-disposal-proceeds-account" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal proceeds account (82x)</span>
                        <select id="category-form-disposal-proceeds-account" wire:model="categoryFormDisposalProceedsAccountId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select an account...</option>
                            @foreach ($accountOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['code'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Create category
                    </button>
                    <button type="button" wire:click="toggleCategoryForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline new-maintenance-request panel. --}}
    @if ($showMaintenanceForm)
        <section aria-label="New maintenance request" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">New Maintenance Request</h2>

            <form wire:submit="saveMaintenanceRequest" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="maintenance-form-title" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Title</span>
                        <input id="maintenance-form-title" type="text" wire:model="maintenanceFormTitle"
                               placeholder="e.g. Projector not powering on"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('maintenanceFormTitle')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-form-asset" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Asset (optional)</span>
                        <select id="maintenance-form-asset" wire:model="maintenanceFormAssetId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Not yet identified</option>
                            @foreach ($assetOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['tag_number'] }} · {{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('maintenanceFormAssetId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="maintenance-form-priority" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Priority</span>
                        <select id="maintenance-form-priority" wire:model="maintenanceFormPriority"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($maintenancePriorityOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="maintenance-form-description" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Description (optional)</span>
                        <textarea id="maintenance-form-description" wire:model="maintenanceFormDescription" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Open request
                    </button>
                    <button type="button" wire:click="toggleMaintenanceForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

<x-list-screen
    title="Asset Register"
    :breadcrumb="['Dashboard', 'Assets']"
    :paginator="$rows"
    empty-message="No asset records match these filters yet. Assets, maintenance requests and depreciation runs appear here as they are set up."
    rail-title="Asset Overview"
>
    <x-slot:actions>
        <button type="button" wire:click="toggleAssetForm"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
            {{ $showAssetForm ? 'Hide form' : 'Register asset' }}
        </button>
        <button type="button" wire:click="toggleCategoryForm"
                class="rounded border border-border-primary px-4 py-2 text-sm font-semibold text-charcoal hover:bg-sand/40">
            {{ $showCategoryForm ? 'Hide form' : 'New category' }}
        </button>
        <button type="button" wire:click="toggleMaintenanceForm"
                class="rounded border border-border-primary px-4 py-2 text-sm font-semibold text-charcoal hover:bg-sand/40">
            {{ $showMaintenanceForm ? 'Hide form' : 'New maintenance request' }}
        </button>
        @if ($tab === 'depreciation')
            <button type="button" wire:click="toggleRunDepreciationForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-semibold text-charcoal hover:bg-sand/40">
                {{ $showRunDepreciationForm ? 'Hide form' : 'Run depreciation' }}
            </button>
        @endif
    </x-slot:actions>

    {{-- Four KPI cards: total assets, net book value, under maintenance,
         depreciation runs pending approval - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Assets" :value="$kpis['total_assets']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 10h16M10 20V10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Net Book Value" :value="Money::of($kpis['net_book_value'])->format(false)" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v10M9 9.5c0-1.4 1.3-2.5 3-2.5s3 .9 3 2.2-1.3 1.9-3 2.3-3 1-3 2.3 1.3 2.2 3 2.2 3-1.1 3-2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Under Maintenance" :value="$kpis['under_maintenance']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M14.7 6.3a4 4 0 00-5.4 5.4L4 17l3 3 5.3-5.3a4 4 0 005.4-5.4l-2.4 2.4-2.3-2.3 2.4-2.4z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Runs Pending Approval" :value="$kpis['runs_pending']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($tab === 'assets')
            <label for="assets-filter-category" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Category</span>
                <select id="assets-filter-category" wire:model.live="category"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All categories</option>
                    @foreach ($categoryOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="assets-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Status</span>
            <select id="assets-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All statuses</option>
                @foreach ($statusOptions as $option)
                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($tab !== 'depreciation')
            <label for="assets-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="assets-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search tag, name, serial..."
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>
        @endif
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
            @if ($tab === 'assets')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Tag</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Acquisition Cost</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Net Book Value</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($tab === 'maintenance')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Title</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Asset</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Priority</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reported</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Estimated Cost</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Fiscal Year</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Assets Processed</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total Charge</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Run At</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="assets-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'assets')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->tag_number }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->acquisition_cost)->format(false) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->net_book_value !== null ? Money::of((int) $row->net_book_value)->format(false) : '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$assetTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex flex-wrap items-center gap-2">
                        <a href="{{ route('assets.show', $row->id) }}"
                           class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                            View
                        </a>
                        @if (in_array($row->status, ['draft', 'in_progress'], true))
                            <button type="button" wire:click="commissionAsset({{ $row->id }})"
                                    wire:confirm="Commission {{ $row->tag_number }} into service?"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                                Commission
                            </button>
                        @endif
                        @if (! in_array($row->status, ['disposed', 'written_off', 'lost'], true))
                            <button type="button"
                                    wire:click="toggleDisposeForm({{ $row->id }}, '{{ addslashes($row->tag_number.' · '.$row->name) }}')"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                                Dispose
                            </button>
                        @endif
                    </div>
                </td>
            @elseif ($tab === 'maintenance')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->title }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->tag_number ?? '—' }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->priority }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->reported_at }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->estimated_cost !== null ? Money::of((int) $row->estimated_cost)->format(false) : '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$maintenanceTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                </td>
                <td class="px-4 py-2.5">
                    @if (! in_array($row->status, ['done', 'cancelled'], true))
                        <button type="button" wire:click="toggleCloseMaintenanceForm({{ $row->id }})"
                                class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                            Close
                        </button>
                    @endif
                </td>
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->fiscal_year_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->period_month }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->assets_processed }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->total_charge)->format(false) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->run_at ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$runTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex flex-wrap items-center gap-2">
                        @if ($row->status === 'calculated')
                            <button type="button" wire:click="approveDepreciationRun({{ $row->id }})"
                                    wire:confirm="Approve depreciation run for {{ $row->fiscal_year_name }} period {{ $row->period_month }}?"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                                Approve
                            </button>
                        @endif
                        @if ($row->status === 'approved')
                            <button type="button" wire:click="postDepreciationRun({{ $row->id }})"
                                    wire:confirm="Post depreciation run for {{ $row->fiscal_year_name }} period {{ $row->period_month }}?"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                                Post
                            </button>
                        @endif
                    </div>
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="assets-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'assets')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->tag_number }} · {{ $row->name }}</p>
                        <x-status-pill :status="$assetTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->category_name }} · NBV {{ $row->net_book_value !== null ? Money::of((int) $row->net_book_value)->format(false) : '—' }}</p>
                @elseif ($tab === 'maintenance')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->title }}</p>
                        <x-status-pill :status="$maintenanceTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm capitalize text-charcoal/70">{{ $row->tag_number ?? 'Unlinked' }} · {{ $row->priority }} priority</p>
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->fiscal_year_name }} · Period {{ $row->period_month }}</p>
                        <x-status-pill :status="$runTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->assets_processed }} assets · {{ Money::of((int) $row->total_charge)->format(false) }}</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
