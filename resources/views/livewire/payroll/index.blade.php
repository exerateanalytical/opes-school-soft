@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $runTone = [
        'draft' => 'amber',
        'calculating' => 'amber',
        'calculated' => 'blue',
        'approved' => 'blue',
        'paid' => 'ok',
        'closed' => 'ok',
        'cancelled' => 'red',
    ];

    $paymentTone = [
        'prepared' => 'amber',
        'exported' => 'blue',
        'confirmed' => 'ok',
        'partially_failed' => 'red',
    ];

    $declarationTone = [
        'not_due' => 'amber',
        'due' => 'amber',
        'generated' => 'blue',
        'filed' => 'blue',
        'paid' => 'ok',
        'late' => 'red',
        'rejected' => 'red',
    ];

    $label = static fn (string $value): string => ucfirst(str_replace('_', ' ', $value));

    $tabs = [
        ['value' => 'runs', 'label' => 'Payroll Runs', 'count' => $tabCounts['runs']],
        ['value' => 'payments', 'label' => 'Payments', 'count' => $tabCounts['payments']],
        ['value' => 'declarations', 'label' => 'Statutory Declarations', 'count' => $tabCounts['declarations']],
    ];
@endphp

@if (session('status'))
    <p class="mb-4 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
        {{ session('status') }}
    </p>
@endif

@error('approve')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@error('preflight')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@error('declarations')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@error('epCnpsEmployerNumber')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@error('pcCode')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@error('srSourceCitation')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

{{-- Inline "configure employer profile" setup panel (payroll.configure). --}}
@if ($showEmployerForm)
    <section aria-label="Configure employer profile" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Configure Employer Profile</h2>
        <p class="mt-1 text-xs text-charcoal/60">Transcribe these values from the school's own CNPS notification letter. Confirming both boxes is mandatory - nothing here is pre-filled.</p>

        <form wire:submit="saveEmployerProfile" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                <label for="ep-cnps-employer-number" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CNPS employer number</span>
                    <input id="ep-cnps-employer-number" type="text" wire:model="epCnpsEmployerNumber"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-dipe-number" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">DIPE number</span>
                    <input id="ep-dipe-number" type="text" wire:model="epDipeNumber"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-niu" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">NIU</span>
                    <input id="ep-niu" type="text" wire:model="epNiu"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-dgi-centre" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">DGI centre (optional)</span>
                    <input id="ep-dgi-centre" type="text" wire:model="epDgiCentre"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-tdl-commune" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">TDL commune ID</span>
                    <input id="ep-tdl-commune" type="number" min="1" wire:model="epTdlCommuneId"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-cnps-regime" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CNPS regime</span>
                    <select id="ep-cnps-regime" wire:model="epCnpsRegime"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="general">General</option>
                        <option value="agricole">Agricole</option>
                        <option value="enseignement_prive">Enseignement privé</option>
                    </select>
                    @error('epCnpsRegime')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="ep-rp-risk-class" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">RP risk class</span>
                    <input id="ep-rp-risk-class" type="text" wire:model="epRpRiskClass"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-cnps-doc-id" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CNPS notification document ID</span>
                    <input id="ep-cnps-doc-id" type="number" min="1" wire:model="epCnpsNotificationDocumentId"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-cnps-doc-ref" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CNPS notification reference</span>
                    <input id="ep-cnps-doc-ref" type="text" wire:model="epCnpsNotificationReference"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="ep-effective-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Effective from</span>
                    <input id="ep-effective-from" type="date" wire:model="epEffectiveFrom"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:gap-6">
                <label class="flex items-center gap-2 text-sm text-charcoal/80">
                    <input type="checkbox" wire:model="epRegimeConfirmed" class="rounded border-border-primary"/>
                    I confirm the CNPS regime against the notification letter
                </label>
                <label class="flex items-center gap-2 text-sm text-charcoal/80">
                    <input type="checkbox" wire:model="epRiskClassConfirmed" class="rounded border-border-primary"/>
                    I confirm the risk class against the notification letter
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Save employer profile
                </button>
                <button type="button" wire:click="toggleEmployerForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "payroll component" setup panel (payroll.configure). --}}
@if ($showComponentForm)
    <section aria-label="Save payroll component" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Payroll Component</h2>
        <p class="mt-1 text-xs text-charcoal/60">Define an allowance, deduction or employer charge. The component code is fixed once created.</p>

        <form wire:submit="saveComponent" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                <label for="pc-code" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Component code</span>
                    <input id="pc-code" type="text" wire:model="pcCode"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="pc-name" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Name</span>
                    <input id="pc-name" type="text" wire:model="pcName"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="pc-name-fr" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Name (French, optional)</span>
                    <input id="pc-name-fr" type="text" wire:model="pcNameFr"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="pc-type" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Type</span>
                    <select id="pc-type" wire:model="pcType"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="earning">Earning (allowance)</option>
                        <option value="employee_deduction">Employee deduction</option>
                        <option value="employer_charge">Employer charge</option>
                        <option value="informational">Informational</option>
                    </select>
                    @error('pcType')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="pc-calculation" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Calculation basis</span>
                    <select id="pc-calculation" wire:model="pcCalculation"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="fixed">Fixed amount</option>
                        <option value="percentage">Percentage</option>
                        <option value="hourly">Hourly</option>
                        <option value="table">Table</option>
                        <option value="formula">Formula</option>
                        <option value="statutory">Statutory</option>
                    </select>
                    @error('pcCalculation')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="pc-calc-order" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Calculation order</span>
                    <input id="pc-calc-order" type="number" min="1" wire:model="pcCalculationOrder"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="pc-statutory-code" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Statutory rate code (if calculation = statutory)</span>
                    <select id="pc-statutory-code" wire:model="pcStatutoryRateCode"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">—</option>
                        <option value="PVID">PVID</option>
                        <option value="PF">PF</option>
                        <option value="RP">RP</option>
                        <option value="IRPP">IRPP</option>
                        <option value="CAC">CAC</option>
                        <option value="CFC">CFC</option>
                        <option value="FNE">FNE</option>
                        <option value="RAV">RAV</option>
                        <option value="TDL">TDL</option>
                    </select>
                </label>

                <label for="pc-effective-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Effective from</span>
                    <input id="pc-effective-from" type="date" wire:model="pcEffectiveFrom"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="pc-formula" class="flex flex-col gap-1 sm:col-span-3">
                    <span class="text-xs font-medium text-charcoal/70">Formula expression (if calculation = formula)</span>
                    <input id="pc-formula" type="text" wire:model="pcFormulaExpression"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex flex-col gap-2 sm:flex-row sm:gap-6">
                <label class="flex items-center gap-2 text-sm text-charcoal/80">
                    <input type="checkbox" wire:model="pcIsTaxable" class="rounded border-border-primary"/>
                    Taxable
                </label>
                <label class="flex items-center gap-2 text-sm text-charcoal/80">
                    <input type="checkbox" wire:model="pcIsCnpsLiable" class="rounded border-border-primary"/>
                    CNPS-liable
                </label>
                <label class="flex items-center gap-2 text-sm text-charcoal/80">
                    <input type="checkbox" wire:model="pcIsEnabled" class="rounded border-border-primary"/>
                    Enabled
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Save component
                </button>
                <button type="button" wire:click="toggleComponentForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "statutory rate" setup panel (payroll.configure). --}}
@if ($showRateForm)
    <section aria-label="Configure statutory rate" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Statutory Rate</h2>
        <p class="mt-1 text-xs text-charcoal/60">Transcribe the value from the school's own CNPS notification letter or DGI notice. A source citation is mandatory.</p>

        <form wire:submit="saveStatutoryRate" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                <label for="sr-code" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Code</span>
                    <select id="sr-code" wire:model="srCode"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="PVID">PVID</option>
                        <option value="PF">PF</option>
                        <option value="RP">RP</option>
                        <option value="IRPP">IRPP</option>
                        <option value="CAC">CAC</option>
                        <option value="CFC">CFC</option>
                        <option value="FNE">FNE</option>
                        <option value="RAV">RAV</option>
                        <option value="TDL">TDL</option>
                    </select>
                    @error('srCode')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="sr-effective-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Effective from</span>
                    <input id="sr-effective-from" type="date" wire:model="srEffectiveFrom"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="sr-risk-class" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Risk class (RP only, optional)</span>
                    <input id="sr-risk-class" type="text" wire:model="srRiskClass"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="sr-cnps-regime" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CNPS regime (PF only, optional)</span>
                    <select id="sr-cnps-regime" wire:model="srCnpsRegime"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">—</option>
                        <option value="general">General</option>
                        <option value="agricole">Agricole</option>
                        <option value="enseignement_prive">Enseignement privé</option>
                    </select>
                </label>

                <label for="sr-employee-rate" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Employee rate (basis points)</span>
                    <input id="sr-employee-rate" type="number" min="0" wire:model="srEmployeeRateBp"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="sr-employer-rate" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Employer rate (basis points)</span>
                    <input id="sr-employer-rate" type="number" min="0" wire:model="srEmployerRateBp"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="sr-flat-amount" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Flat amount (mutually exclusive with rates)</span>
                    <input id="sr-flat-amount" type="number" min="0" wire:model="srFlatAmount"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>

                <label for="sr-source-citation" class="flex flex-col gap-1 sm:col-span-3">
                    <span class="text-xs font-medium text-charcoal/70">Source citation (mandatory)</span>
                    <input id="sr-source-citation" type="text" wire:model="srSourceCitation"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Save statutory rate
                </button>
                <button type="button" wire:click="toggleRateForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "start payroll run" panel (money-sensitive; no separate route). --}}
@if ($showForm)
    <section aria-label="Start payroll run" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Start Payroll Run</h2>

        <form wire:submit="startRun" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="payroll-form-month" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Payroll month</span>
                    <input id="payroll-form-month" type="month" wire:model="formPayrollMonth"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formPayrollMonth')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="payroll-form-run-type" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Run type</span>
                    <select id="payroll-form-run-type" wire:model="formRunType"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="regular">Regular</option>
                        <option value="thirteenth_month">13th month</option>
                        <option value="final_settlement">Final settlement</option>
                        <option value="regularisation">Regularisation</option>
                    </select>
                    @error('formRunType')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Calculate run
                </button>
                <button type="button" wire:click="toggleForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "prepare payment" panel for the row selected via togglePayForm(). --}}
@if ($payRunId !== null)
    <section aria-label="Prepare payment" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Prepare Payment for Run #{{ $payRunId }}</h2>

        <form wire:submit="preparePayment" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-3">
                <label for="payroll-pay-method" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Payment method</span>
                    <select id="payroll-pay-method" wire:model="payMethod"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="bank">Bank</option>
                        <option value="mobile_money">Mobile money</option>
                        <option value="cash">Cash</option>
                    </select>
                </label>

                <label for="payroll-pay-treasury" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Treasury account ID</span>
                    <input id="payroll-pay-treasury" type="number" min="1" wire:model="payTreasuryAccountId"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('payTreasuryAccountId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="payroll-pay-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Value date</span>
                    <input id="payroll-pay-date" type="date" wire:model="payValueDate"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('payValueDate')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Prepare payment
                </button>
                <button type="button" wire:click="togglePayForm(null)"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "reverse run" panel: the reason is mandatory (05-hr-payroll 8.7). --}}
@if ($reverseRunId !== null)
    <section aria-label="Reverse payroll run" class="mb-4 rounded-lg border border-heritage-red/40 bg-heritage-red/5 p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Reverse Run #{{ $reverseRunId }}</h2>
        <p class="mt-1 text-xs text-charcoal/60">This cancels the run and posts a contrepassation journal entry. It cannot be undone.</p>

        <form wire:submit="reverseRun" class="mt-4 space-y-4">
            <label for="payroll-reverse-reason" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Reversal reason (minimum 10 characters)</span>
                <textarea id="payroll-reverse-reason" wire:model="reverseReason" rows="2"
                          class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                @error('reverseReason')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            <div class="flex items-center gap-3">
                <button type="submit" wire:confirm="Reverse this payroll run? This cancels it and posts a contrepassation entry."
                        class="rounded bg-heritage-red px-4 py-2 text-sm font-semibold text-white hover:bg-heritage-red/90">
                    Reverse run
                </button>
                <button type="button" wire:click="toggleReverseForm(null)"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

<x-list-screen
    title="Payroll"
    :breadcrumb="['Dashboard', 'Payroll']"
    :paginator="$rows"
    empty-message="No payroll records match these filters yet. Runs, payments and declarations appear here as they are processed."
    rail-title="Payroll Overview"
>
    <x-slot:actions>
        <button type="button" wire:click="toggleEmployerForm"
                class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
            {{ $showEmployerForm ? 'Hide employer profile' : 'Configure employer profile' }}
        </button>
        <button type="button" wire:click="toggleComponentForm"
                class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
            {{ $showComponentForm ? 'Hide component form' : 'New payroll component' }}
        </button>
        <button type="button" wire:click="toggleRateForm"
                class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
            {{ $showRateForm ? 'Hide rate form' : 'Configure statutory rate' }}
        </button>
        <button type="button" wire:click="toggleForm"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
            {{ $showForm ? 'Hide form' : 'Start payroll run' }}
        </button>
    </x-slot:actions>

    {{-- Four KPI cards: total runs, last run's net pay, runs pending
         approval, staff paid this month - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Payroll Runs" :value="$kpis['total_runs']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M8 9h8M8 13h8M8 17h4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Last Run Net Pay" :value="Money::of($kpis['last_run_net_pay'])->format(false)" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v10M9 9.5c0-1.4 1.3-2.5 3-2.5s3 1 3 2.3c0 3-6 1.7-6 4.7 0 1.4 1.3 2.5 3 2.5s3-1.1 3-2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Pending Approval" :value="$kpis['pending_approval']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v6l4 2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Staff Paid This Month" :value="$kpis['staff_paid_this_month']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($statusOptions !== [])
            <label for="payroll-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="payroll-filter-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="payroll-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="payroll-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search run type, reference..."
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
            @if ($tab === 'runs')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Payroll Month</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Run Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Employer</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Staff</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Total Net Pay</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            @elseif ($tab === 'payments')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Value Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Payroll Month</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Method</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Lines</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Payee</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Period</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Due Date</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="payroll-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'runs')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ \Illuminate\Support\Carbon::parse($row->payroll_month)->format('F Y') }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $label($row->run_type) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->cnps_employer_number ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->staff_count }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->total_net)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$runTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <div class="flex items-center justify-end gap-3">
                        <a href="{{ route('payroll.runs.show', ['run' => $row->id]) }}"
                           class="text-sm font-medium text-charcoal/70 hover:underline">
                            View
                        </a>
                        @if (in_array($row->status, ['draft', 'calculated'], true))
                            <button type="button" wire:click="preflightRun({{ $row->id }})"
                                    class="text-sm font-medium text-charcoal/70 hover:underline">
                                Preflight
                            </button>
                        @endif
                        @if ($row->status === 'calculated')
                            <button type="button" wire:click="approveRun({{ $row->id }})"
                                    wire:confirm="Approve this payroll run? This posts the ledger entry and cannot be undone."
                                    class="text-sm font-medium text-primary hover:underline">
                                Approve
                            </button>
                        @endif
                        @if ($row->status === 'approved')
                            <button type="button" wire:click="togglePayForm({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Prepare payment
                            </button>
                        @endif
                        @if (in_array($row->status, ['paid', 'closed'], true))
                            <button type="button" wire:click="generateDeclarations({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Generate declarations
                            </button>
                        @endif
                        @if (in_array($row->status, ['approved', 'paid'], true))
                            <button type="button" wire:click="toggleReverseForm({{ $row->id }})"
                                    class="text-sm font-medium text-heritage-red hover:underline">
                                Reverse
                            </button>
                        @endif
                    </div>
                </td>
            @elseif ($tab === 'payments')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->value_date }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ \Illuminate\Support\Carbon::parse($row->payroll_month)->format('F Y') }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $label($row->payment_method) }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->lines_count }}{{ $row->failed_count > 0 ? ' ('.$row->failed_count.' failed)' : '' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->total_amount)->format(false) }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$paymentTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $label($row->type) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->payee }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    @if ($row->period_month !== null)
                        {{ \Illuminate\Support\Carbon::parse($row->period_month)->format('F Y') }}
                    @elseif ($row->period_year !== null)
                        {{ $row->period_year }}
                    @else
                        —
                    @endif
                    @if ($row->type === 'staff_departure' && $row->first_name !== null)
                        <span class="block text-xs text-charcoal/60">{{ trim($row->first_name.' '.$row->last_name) }}</span>
                    @endif
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->due_date ?? 'Deadline not configured' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->amount_declared !== null ? Money::of((int) $row->amount_declared)->format(false) : '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$declarationTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="payroll-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'runs')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ \Illuminate\Support\Carbon::parse($row->payroll_month)->format('F Y') }}</p>
                        <x-status-pill :status="$runTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $label($row->run_type) }} · {{ $row->staff_count }} staff · {{ Money::of((int) $row->total_net)->format(false) }}</p>
                @elseif ($tab === 'payments')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->value_date }}</p>
                        <x-status-pill :status="$paymentTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $label($row->payment_method) }} · {{ $row->lines_count }} lines · {{ Money::of((int) $row->total_amount)->format(false) }}</p>
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $label($row->type) }}</p>
                        <x-status-pill :status="$declarationTone[$row->status] ?? 'ok'" :label="$label($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">
                        {{ $row->payee }} ·
                        {{ $row->period_month !== null ? \Illuminate\Support\Carbon::parse($row->period_month)->format('F Y') : $row->period_year }}
                        · {{ $row->due_date ?? 'Deadline not configured' }}
                    </p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
