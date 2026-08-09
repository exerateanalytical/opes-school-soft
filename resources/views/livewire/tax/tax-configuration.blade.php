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

    <nav class="flex gap-1 border-b border-sand" aria-label="Tax configuration tabs">
        @foreach (['tax-codes' => 'Tax codes', 'withholding' => 'Withholding', 'prorata' => 'Prorata', 'obligations' => 'Obligations'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')"
                    class="{{ $tab === $key ? 'border-b-2 border-primary font-semibold text-charcoal' : 'text-charcoal/60' }} px-3 py-2 text-sm">
                {{ $label }}
            </button>
        @endforeach
    </nav>

    @if ($tab === 'tax-codes')
        <div class="overflow-x-auto rounded border border-sand bg-white">
            <table class="min-w-full text-sm">
                <thead>
                <tr class="bg-chrome text-left text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Code</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Rate</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Direction</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">In force</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($taxCodes as $taxCode)
                    <tr class="border-t border-sand">
                        <td class="px-4 py-2 font-mono">{{ $taxCode->code }}</td>
                        <td class="px-4 py-2">{{ $taxCode->name_fr }}</td>
                        <td class="px-4 py-2">{{ $taxCode->is_exempt ? 'exempt' : $taxCode->rate()->toPercentString().'%' }}</td>
                        <td class="px-4 py-2">{{ $taxCode->direction }}</td>
                        <td class="px-4 py-2">{{ $taxCode->effective_from->toDateString() }} &rarr; {{ $taxCode->effective_to?->toDateString() ?? 'open' }}</td>
                        <td class="px-4 py-2">{{ $taxCode->is_active ? 'active' : 'inactive' }}</td>
                    </tr>
                @empty
                    <tr class="border-t border-sand">
                        <td colspan="6" class="px-4 py-6 text-center text-charcoal/60">
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
            <div class="rounded border border-sand bg-white px-4 py-3 text-sm text-charcoal">
                Recognition basis:
                <strong>{{ $settings?->withholding_recognition?->value ?? 'not decided (blocks withholding)' }}</strong>
            </div>
            <div class="overflow-x-auto rounded border border-sand bg-white">
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
                    </tr>
                    </thead>
                    <tbody>
                    @forelse ($withholdingRules as $rule)
                        <tr class="border-t border-sand">
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
                        </tr>
                    @empty
                        <tr class="border-t border-sand">
                            <td colspan="8" class="px-4 py-6 text-center text-charcoal/60">
                                No withholding rule configured. A school that pays a supplier without
                                withholding is personally liable for the tax plus penalties &mdash;
                                configure the rules with your accountant.
                            </td>
                        </tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
            <div class="rounded border border-sand bg-white px-4 py-3 text-sm text-charcoal">
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
            <div class="rounded border border-sand bg-white px-4 py-3 text-sm text-charcoal">
                Rounding rule:
                <strong>{{ $settings?->prorata_rounding?->value ?? 'not decided (blocks computation)' }}</strong>
            </div>
            <div class="overflow-x-auto rounded border border-sand bg-white">
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
                        <tr class="border-t border-sand">
                            <td class="px-4 py-2">{{ $fiscalYearCodes[$prorata->fiscal_year_id] ?? $prorata->fiscal_year_id }}</td>
                            <td class="px-4 py-2">{{ $prorata->basis->value }}</td>
                            <td class="px-4 py-2">{{ $prorata->rate()->toPercentString() }}%</td>
                            <td class="px-4 py-2 text-right">{{ number_format($prorata->numerator_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-2 text-right">{{ number_format($prorata->denominator_amount, 0, ',', ' ') }}</td>
                            <td class="px-4 py-2">{{ $prorata->isConfirmed() ? 'confirmed' : 'not confirmed - unusable' }}</td>
                        </tr>
                    @empty
                        <tr class="border-t border-sand">
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
        <div class="overflow-x-auto rounded border border-sand bg-white">
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
                    <tr class="border-t border-sand">
                        <td class="px-4 py-2">{{ $obligation->declarationType?->name_fr }}</td>
                        <td class="px-4 py-2">{{ $obligation->frequency }}</td>
                        <td class="px-4 py-2 font-mono text-xs">{{ $obligation->due_rule }}</td>
                        <td class="px-4 py-2 text-xs">{{ $obligation->penalty_note }}</td>
                    </tr>
                @empty
                    <tr class="border-t border-sand">
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
