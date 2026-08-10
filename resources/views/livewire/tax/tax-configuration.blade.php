{{--
    03-tax-procurement §10 - tax configuration cockpit. Literal text (not
    lang keys): lang/{en,fr}/opes.php is owned by Agent F5's wiring pass.
    Every "Not configured — blocks use" badge is the 00-core §16 blocking
    state made visible.
--}}
<div class="mx-auto max-w-6xl space-y-6 p-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">Tax configuration</h1>
        <p class="text-sm text-charcoal/70">
            Nothing here is pre-seeded: rates and rules marked "needs verification" in the specification
            ship empty and block use until configured with your accountant.
        </p>
    </div>

    <div class="flex flex-wrap gap-2 text-xs">
        @if (! $identityConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Fiscal identity: not confirmed &mdash; blocks document printing
            </span>
        @endif
        @if (! $taxCodesConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Tax codes: not configured &mdash; blocks TVA computation
            </span>
        @endif
        @if (! $withholdingConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Withholding rules: not configured &mdash; blocks supplier invoices and payments
            </span>
        @endif
        @if (! $recognitionConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Withholding recognition (invoice vs payment): not decided &mdash; blocks withholding
            </span>
        @endif
        @if (! $prorataRoundingConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Prorata rounding rule: not decided &mdash; blocks prorata computation
            </span>
        @endif
        @if (! $prorataConfigured)
            <span class="rounded-full border border-red-300 bg-red-50 px-3 py-1 font-medium text-red-800">
                Prorata de d&eacute;duction: none confirmed &mdash; blocks input-VAT deduction
            </span>
        @endif
    </div>

    <nav class="flex gap-1 border-b border-border-primary" aria-label="Tax configuration tabs">
        @foreach (['tax-codes' => 'Tax codes', 'withholding' => 'Withholding', 'prorata' => 'Prorata', 'obligations' => 'Obligations'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    class="{{ $tab === $key ? 'border-b-2 border-primary font-semibold text-charcoal' : 'text-charcoal/60' }} px-3 py-2 text-sm">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if (session('status'))
        <div class="rounded border border-green-300 bg-green-50 px-4 py-3 text-sm text-green-800" role="status">
            {{ session('status') }}
        </div>
    @endif

    @if ($tab === 'tax-codes')
        <div class="flex justify-end">
            <button type="button" wire:click="toggleTaxCodeForm"
                    class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                {{ $showTaxCodeForm ? 'Cancel' : 'Configure a tax code' }}
            </button>
        </div>

        @if ($showTaxCodeForm)
            <form wire:submit="saveTaxCode" class="space-y-3 rounded border border-border-primary bg-white p-4">
                @error('tcCode') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                <div class="grid gap-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Code</span>
                        <input type="text" wire:model="tcCode" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($taxCodeId !== null)/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (en)</span>
                        <input type="text" wire:model="tcName" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Name (fr)</span>
                        <input type="text" wire:model="tcNameFr" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Tax type</span>
                        <select wire:model="tcTaxType" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                            <option value="tva">tva</option>
                            <option value="withholding_air">withholding_air</option>
                            <option value="withholding_precompte">withholding_precompte</option>
                            <option value="other">other</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Rate (basis points, 100000 = 100%)</span>
                        <input type="number" wire:model="tcRateBp" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($taxCodeId !== null)/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Direction</span>
                        <select wire:model="tcDirection" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                            <option value="output">output</option>
                            <option value="input">input</option>
                            <option value="both">both</option>
                        </select>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Effective from</span>
                        <input type="date" wire:model="tcEffectiveFrom" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($taxCodeId !== null)/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Effective to (empty = open)</span>
                        <input type="date" wire:model="tcEffectiveTo" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Exemption legal ref</span>
                        <input type="text" wire:model="tcExemptionLegalRef" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                </div>
                <div class="flex flex-wrap gap-4">
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="tcIsExempt"/> Exempt
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="tcIsZeroRated"/> Zero-rated
                    </label>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="tcIsActive"/> Active
                    </label>
                </div>
                <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Save tax code</button>
            </form>
        @endif

        <div class="overflow-x-auto rounded border border-border-primary bg-white">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="bg-chrome text-left text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Rate</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Direction</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">In force</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"></th>
                </tr>
                </thead>
                <tbody>
                @forelse ($taxCodes as $taxCode)
                    <tr class="border-t border-border-primary">
                        <td class="px-4 py-2 font-mono">{{ $taxCode->code }}</td>
                        <td class="px-4 py-2">{{ $taxCode->name_fr }}</td>
                        <td class="px-4 py-2">{{ $taxCode->is_exempt ? 'exempt' : $taxCode->rate()->toPercentString().'%' }}</td>
                        <td class="px-4 py-2">{{ $taxCode->direction }}</td>
                        <td class="px-4 py-2">{{ $taxCode->effective_from->toDateString() }} &rarr; {{ $taxCode->effective_to?->toDateString() ?? 'open' }}</td>
                        <td class="px-4 py-2">{{ $taxCode->is_active ? 'active' : 'inactive' }}</td>
                        <td class="px-4 py-2">
                            <button type="button" wire:click="editTaxCode({{ $taxCode->id }})" class="text-xs font-medium text-charcoal underline">Edit</button>
                        </td>
                    </tr>
                @empty
                    <tr class="border-t border-border-primary">
                        <td colspan="7" class="px-4 py-6 text-center text-charcoal/60">
                            No tax code configured. Configure them with your accountant &mdash; the TVA
                            engine refuses to compute until at least one active code exists.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
        </div>
    @elseif ($tab === 'withholding')
        <div class="space-y-4">
            <div class="flex items-center justify-between rounded border border-border-primary bg-white px-4 py-3 text-sm text-charcoal">
                <span>
                    Recognition basis:
                    <strong>{{ $settings?->withholding_recognition?->value ?? 'not decided (blocks withholding)' }}</strong>
                </span>
                <button type="button" wire:click="toggleTaxSettingsForm" class="text-xs font-medium text-charcoal underline">
                    {{ $showTaxSettingsForm ? 'Cancel' : 'Decide settings' }}
                </button>
            </div>

            @if ($showTaxSettingsForm)
                <form wire:submit="saveTaxSettings" class="space-y-3 rounded border border-border-primary bg-white p-4">
                    @error('tsWithholdingRecognition') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="grid gap-3 sm:grid-cols-2">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Withholding recognition</span>
                            <select wire:model="tsWithholdingRecognition" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                                <option value="">&mdash;</option>
                                <option value="on_invoice">on_invoice</option>
                                <option value="on_payment">on_payment</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Prorata rounding</span>
                            <select wire:model="tsProrataRounding" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                                <option value="">&mdash;</option>
                                <option value="exact_bp">exact_bp</option>
                                <option value="up_to_whole_percent">up_to_whole_percent</option>
                            </select>
                        </label>
                    </div>
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Save settings</button>
                </form>
            @endif

            <div class="flex justify-end gap-2">
                <button type="button" wire:click="toggleWithholdingProfileForm"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-semibold text-charcoal">
                    {{ $showWithholdingProfileForm ? 'Cancel profile' : 'Configure a profile' }}
                </button>
                <button type="button" wire:click="toggleWithholdingRuleForm"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                    {{ $showWithholdingRuleForm ? 'Cancel rule' : 'Configure a rule' }}
                </button>
            </div>

            @if ($showWithholdingRuleForm)
                <form wire:submit="saveWithholdingRule" class="space-y-3 rounded border border-border-primary bg-white p-4">
                    @error('wrCode') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Code</span>
                            <input type="text" wire:model="wrCode" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($withholdingRuleId !== null)/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Name (en)</span>
                            <input type="text" wire:model="wrName" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Name (fr)</span>
                            <input type="text" wire:model="wrNameFr" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Withholding type</span>
                            <select wire:model="wrWithholdingType" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($withholdingRuleId !== null)>
                                <option value="air">air</option>
                                <option value="precompte_achats">precompte_achats</option>
                                <option value="precompte_station_service">precompte_station_service</option>
                                <option value="no_contributor_card">no_contributor_card</option>
                                <option value="niu_inactive">niu_inactive</option>
                                <option value="other">other</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Rate (basis points)</span>
                            <input type="number" wire:model="wrRateBp" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($withholdingRuleId !== null)/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Base</span>
                            <select wire:model="wrBase" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                                <option value="">unset</option>
                                <option value="amount_ht">amount_ht</option>
                                <option value="amount_ttc">amount_ttc</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Applies to</span>
                            <select wire:model="wrAppliesTo" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                                <option value="services">services</option>
                                <option value="goods">goods</option>
                                <option value="both">both</option>
                                <option value="rent">rent</option>
                                <option value="commission">commission</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Minimum base</span>
                            <input type="number" wire:model="wrMinimumBase" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Priority</span>
                            <input type="number" wire:model="wrPriority" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Legal ref</span>
                            <input type="text" wire:model="wrLegalRef" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Effective from</span>
                            <input type="date" wire:model="wrEffectiveFrom" class="rounded border border-border-primary px-2 py-1.5 text-sm" @disabled($withholdingRuleId !== null)/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Effective to (empty = open)</span>
                            <input type="date" wire:model="wrEffectiveTo" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                    </div>
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" wire:model="wrIsActive"/> Active
                    </label>
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Save rule</button>
                </form>
            @endif

            @if ($showWithholdingProfileForm)
                <form wire:submit="saveWithholdingProfile" class="space-y-3 rounded border border-border-primary bg-white p-4">
                    @error('wpCode') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    @error('wpRulesCsv') <p class="text-sm text-red-700">{{ $message }}</p> @enderror
                    <div class="grid gap-3 sm:grid-cols-3">
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Code</span>
                            <input type="text" wire:model="wpCode" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Name (en)</span>
                            <input type="text" wire:model="wpName" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Name (fr)</span>
                            <input type="text" wire:model="wpNameFr" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        </label>
                    </div>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Rules (rule_id:sequence, comma-separated)</span>
                        <input type="text" wire:model="wpRulesCsv" placeholder="1:1,2:2" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">Save profile</button>
                </form>
            @endif

            <div class="overflow-x-auto rounded border border-border-primary bg-white">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="bg-chrome text-left text-white">
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Rate</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Base</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Applies to</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Priority</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">In force</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"></th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($withholdingRules as $rule)
                        <tr class="border-t border-border-primary">
                            <td class="px-4 py-2 font-mono">{{ $rule->code }}</td>
                            <td class="px-4 py-2">{{ $rule->withholding_type->value }}</td>
                            <td class="px-4 py-2">{{ $rule->rate()->toPercentString() }}%</td>
                            <td class="px-4 py-2">{{ $rule->base?->value ?? 'unset (cannot activate)' }}</td>
                            <td class="px-4 py-2">{{ $rule->applies_to }}</td>
                            <td class="px-4 py-2">{{ $rule->priority }}</td>
                            <td class="px-4 py-2">{{ $rule->effective_from->toDateString() }} &rarr; {{ $rule->effective_to?->toDateString() ?? 'open' }}</td>
                            <td class="px-4 py-2">
                                {{ $rule->isConfirmed() ? 'confirmed' : 'not confirmed - never applied' }}
                            </td>
                            <td class="px-4 py-2 space-x-2">
                                <button type="button" wire:click="editWithholdingRule({{ $rule->id }})" class="text-xs font-medium text-charcoal underline">Edit</button>
                                @if (! $rule->isConfirmed())
                                    <button type="button" wire:click="confirmWithholdingRule({{ $rule->id }})" wire:confirm="Activate this withholding rule?" class="text-xs font-medium text-primary underline">Activate</button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr class="border-t border-border-primary">
                            <td colspan="9" class="px-4 py-6 text-center text-charcoal/60">
                                No withholding rule configured. A school that pays a supplier without
                                withholding is personally liable for the tax plus penalties &mdash;
                                configure the rules with your accountant.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="rounded border border-border-primary bg-white px-4 py-3 text-sm text-charcoal">
                Profiles:
                @forelse ($withholdingProfiles as $profile)
                    <span class="mr-2 font-mono">{{ $profile->code }}</span>
                @empty
                    <span class="text-charcoal/60">none (suppliers resolve dynamically)</span>
                @endforelse
            </div>
        </div>
    @elseif ($tab === 'prorata')
        <div class="space-y-4">
            <div class="rounded border border-border-primary bg-white px-4 py-3 text-sm text-charcoal">
                Rounding rule:
                <strong>{{ $settings?->prorata_rounding?->value ?? 'not decided (blocks computation)' }}</strong>
            </div>
            <div class="overflow-x-auto rounded border border-border-primary bg-white">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="bg-chrome text-left text-white">
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Fiscal year</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Basis</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Rate</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Numerator HT</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Denominator HT</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($proratas as $prorata)
                        <tr class="border-t border-border-primary">
                            <td class="px-4 py-2">{{ $fiscalYearCodes[$prorata->fiscal_year_id] ?? $prorata->fiscal_year_id }}</td>
                            <td class="px-4 py-2">{{ $prorata->basis->value }}</td>
                            <td class="px-4 py-2">{{ $prorata->rate()->toPercentString() }}%</td>
                            <td class="px-4 py-2 text-right">{{ number_format($prorata->numerator_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($prorata->denominator_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-2">{{ $prorata->isConfirmed() ? 'confirmed' : 'not confirmed - unusable' }}</td>
                        </tr>
                    @empty
                        <tr class="border-t border-border-primary">
                            <td colspan="6" class="px-4 py-6 text-center text-charcoal/60">
                                No prorata computed. Input-VAT deduction is blocked until a prorata is
                                computed and confirmed for the fiscal year.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @else
        <div class="overflow-x-auto rounded border border-border-primary bg-white">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="bg-chrome text-left text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Declaration</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Frequency</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Due rule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Penalty</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($obligations as $obligation)
                    <tr class="border-t border-border-primary">
                        <td class="px-4 py-2">{{ $obligation->declarationType?->name_fr }}</td>
                        <td class="px-4 py-2">{{ $obligation->frequency }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $obligation->due_rule }}</td>
                        <td class="px-4 py-2 text-xs">{{ $obligation->penalty_note }}</td>
                    </tr>
                @empty
                    <tr class="border-t border-border-primary">
                        <td colspan="4" class="px-4 py-6 text-center text-charcoal/60">
                            No obligations configured.
                        </td>
                    </tr>
                @endforelse
                </tbody>
            </table>
            <p class="px-4 py-3 text-xs text-charcoal/60">
                This system never files anything: it generates figures and exports; the bursar files on
                impots.cm. Statutory dates are shown without weekend/holiday adjustment (unverified).
            </p>
        </div>
    @endif
</div>
