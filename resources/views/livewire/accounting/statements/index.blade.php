@php
    use App\Support\Money\Money;

    $tabs = [
        ['value' => 'bilan', 'label' => 'Bilan'],
        ['value' => 'resultat', 'label' => 'Compte de résultat'],
        ['value' => 'flux', 'label' => 'Tableau des flux'],
        ['value' => 'comparative', 'label' => 'Comparative'],
    ];

    $fmt = static fn (int $amount): string => Money::of($amount)->format(false);
@endphp

<div class="min-w-0 space-y-4 print:space-y-2">
    <style>
        @media print {
            nav, .no-print { display: none !important; }
            body { background: #fff; }
            .print-block { break-inside: avoid; }
        }
    </style>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div>
            <p class="text-xs uppercase tracking-wide text-charcoal/50">Dashboard / Ledger / Financial Statements</p>
            <h1 class="text-xl font-semibold text-charcoal">Financial Statements (OHADA)</h1>
        </div>

        <div class="flex items-center gap-2 no-print">
            <button type="button" wire:click="exportExcel"
                    class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Export Excel
            </button>
            <button type="button" wire:click="exportPdf"
                    class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Export PDF
            </button>
            <button type="button" onclick="window.print()"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                Print
            </button>
        </div>
    </div>

    <div class="flex flex-wrap items-end gap-3 rounded border border-border-primary bg-white p-3 no-print">
        <label for="fs-fiscal-year" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
            <select id="fs-fiscal-year" wire:model.live="fiscalYearId"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">Select…</option>
                @foreach ($fiscalYearOptions as $fiscalYear)
                    <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->code }}</option>
                @endforeach
            </select>
        </label>

        <label for="fs-period-from" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Period from</span>
            <select id="fs-period-from" wire:model.live="periodFromId"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">Year start</option>
                @foreach ($accountingPeriodOptions as $period)
                    <option value="{{ $period->id }}">{{ $period->period_month->format('Y-m') }}</option>
                @endforeach
            </select>
        </label>

        <label for="fs-period-to" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Period to</span>
            <select id="fs-period-to" wire:model.live="periodToId"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">Year end</option>
                @foreach ($accountingPeriodOptions as $period)
                    <option value="{{ $period->id }}">{{ $period->period_month->format('Y-m') }}</option>
                @endforeach
            </select>
        </label>

        @if ($tab === 'comparative')
            <label for="fs-comparative-of" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Compare</span>
                <select id="fs-comparative-of" wire:model.live="comparativeOf"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="resultat">Compte de résultat</option>
                    <option value="bilan">Bilan (totals)</option>
                </select>
            </label>
        @endif

        <button type="button" wire:click="resetFilters"
                class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal/70 hover:text-charcoal">
            Reset
        </button>
    </div>

    <div class="flex flex-wrap gap-1 border-b border-border-primary no-print">
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
            </button>
        @endforeach
    </div>

    @if ($window === null)
        <div class="rounded border border-border-primary bg-white p-6 text-sm text-charcoal/70">
            Select a fiscal year to compute the financial statements. Nothing is shown without one — every figure on this
            screen is a live ledger aggregate, never a placeholder.
        </div>
    @else
        <p class="text-xs text-charcoal/60">
            Reporting window: <span class="font-mono">{{ $window['from'] }}</span> →
            <span class="font-mono">{{ $window['to'] }}</span>.
            The Bilan is cumulative from <span class="font-mono">{{ $window['yearStart'] }}</span>; the Compte de résultat
            and the Tableau des flux cover the window only.
        </p>

        @if ($tab === 'bilan' && $bilan !== null)
            @if (! $bilan['has_data'])
                <div class="rounded border border-border-primary bg-white p-6 text-sm text-charcoal/70">
                    No posted ledger movement in this fiscal year up to {{ $window['to'] }} — there is no bilan to compute.
                </div>
            @else
                <p class="text-xs text-charcoal/60">Classification basis: {{ $bilan['basis'] }}.</p>

                @if ($bilan['difference'] !== 0)
                    <div class="rounded border border-red-300 bg-red-50 p-3 text-sm text-red-800 print-block">
                        <strong>The bilan does not balance.</strong>
                        Total Actif {{ $fmt($bilan['total_actif']) }} − Total Passif {{ $fmt($bilan['total_passif']) }}
                        = <span class="font-mono">{{ $fmt($bilan['difference']) }}</span> FCFA.
                        @if ($bilan['excluded_total'] !== 0)
                            Net movement on off-balance / analytic accounts (class 9, excluded from the bilan by
                            definition) is <span class="font-mono">{{ $fmt($bilan['excluded_total']) }}</span> FCFA —
                            listed below; entries posted with a class-9 counterpart are the usual cause.
                        @endif
                    </div>
                @else
                    <div class="rounded border border-emerald-300 bg-emerald-50 p-3 text-sm text-emerald-800 print-block">
                        <strong>Bilan équilibré.</strong> Total Actif = Total Passif = {{ $fmt($bilan['total_actif']) }} FCFA.
                    </div>
                @endif

                <div class="grid gap-4 lg:grid-cols-2">
                    @foreach ([['Actif', $bilan['actif'], $bilan['total_actif']], ['Passif', $bilan['passif'], $bilan['total_passif']]] as $side)
                        <div class="overflow-x-auto rounded border border-border-primary bg-white print-block">
                            <table class="min-w-full text-sm">
                                <thead>
                                    <tr class="bg-chrome text-white">
                                        <th scope="col" colspan="2" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">{{ $side[0] }}</th>
                                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Montant (FCFA)</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-border-primary">
                                    @foreach ($side[1] as $section)
                                        <tr class="bg-sand/30">
                                            <th scope="row" colspan="3" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-charcoal">{{ $section['label'] }}</th>
                                        </tr>
                                        @foreach ($section['lines'] as $line)
                                            <tr>
                                                <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['code'] }}</td>
                                                <td class="px-4 py-2 text-charcoal">{{ $line['name'] }}</td>
                                                <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['amount']) }}</td>
                                            </tr>
                                        @endforeach
                                        <tr class="bg-sand/10">
                                            <td colspan="2" class="px-4 py-2 text-right text-xs font-semibold text-charcoal/70">Sous-total</td>
                                            <td class="px-4 py-2 text-right font-mono font-semibold text-charcoal">{{ $fmt($section['total']) }}</td>
                                        </tr>
                                    @endforeach
                                    <tr class="bg-chrome/10">
                                        <td colspan="2" class="px-4 py-2.5 text-right text-sm font-semibold text-charcoal">TOTAL {{ strtoupper($side[0]) }}</td>
                                        <td class="px-4 py-2.5 text-right font-mono text-sm font-bold text-charcoal">{{ $fmt($side[2]) }}</td>
                                    </tr>
                                </tbody>
                            </table>
                        </div>
                    @endforeach
                </div>

                @if ($bilan['excluded'] !== [])
                    <div class="overflow-x-auto rounded border border-border-primary bg-white print-block">
                        <table class="min-w-full text-sm">
                            <thead>
                                <tr class="bg-chrome text-white">
                                    <th scope="col" colspan="2" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Hors bilan — comptes de classe 9 (exclus du bilan)</th>
                                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Solde (FCFA)</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary">
                                @foreach ($bilan['excluded'] as $line)
                                    <tr>
                                        <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['code'] }}</td>
                                        <td class="px-4 py-2 text-charcoal">{{ $line['name'] }}</td>
                                        <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['amount']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            @endif
        @endif

        @if ($tab === 'resultat' && $resultat !== null)
            @if (! $resultat['has_data'])
                <div class="rounded border border-border-primary bg-white p-6 text-sm text-charcoal/70">
                    No class 6/7 movement in this window — there is no compte de résultat to compute.
                </div>
            @else
                <p class="text-xs text-charcoal/60">Classification basis: {{ $resultat['basis'] }}.</p>

                <div class="overflow-x-auto rounded border border-border-primary bg-white print-block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Code</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Libellé</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Montant (FCFA)</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            <tr class="bg-sand/30">
                                <th scope="row" colspan="3" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-charcoal">Produits (classe 7)</th>
                            </tr>
                            @foreach ($resultat['produits'] as $line)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['code'] }}</td>
                                    <td class="px-4 py-2 text-charcoal">{{ $line['name'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['amount']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-sand/10">
                                <td colspan="2" class="px-4 py-2 text-right text-xs font-semibold text-charcoal/70">Total produits</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold text-charcoal">{{ $fmt($resultat['total_produits']) }}</td>
                            </tr>

                            <tr class="bg-sand/30">
                                <th scope="row" colspan="3" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide text-charcoal">Charges (classe 6)</th>
                            </tr>
                            @foreach ($resultat['charges'] as $line)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['code'] }}</td>
                                    <td class="px-4 py-2 text-charcoal">{{ $line['name'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['amount']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-sand/10">
                                <td colspan="2" class="px-4 py-2 text-right text-xs font-semibold text-charcoal/70">Total charges</td>
                                <td class="px-4 py-2 text-right font-mono font-semibold text-charcoal">{{ $fmt($resultat['total_charges']) }}</td>
                            </tr>

                            <tr class="bg-chrome/10">
                                <td colspan="2" class="px-4 py-2.5 text-right text-sm font-semibold text-charcoal">
                                    RÉSULTAT NET ({{ $resultat['net'] >= 0 ? 'bénéfice' : 'perte' }})
                                </td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-bold {{ $resultat['net'] >= 0 ? 'text-emerald-700' : 'text-red-700' }}">
                                    {{ $fmt($resultat['net']) }}
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        @if ($tab === 'flux' && $flux !== null)
            <div class="rounded border {{ $flux['mapped'] ? 'border-border-primary bg-white' : 'border-amber-300 bg-amber-50' }} p-3 text-sm {{ $flux['mapped'] ? 'text-charcoal/70' : 'text-amber-900' }} print-block">
                <strong>Basis:</strong> {{ $flux['basis'] }}
            </div>

            @if (! $flux['has_data'])
                <div class="rounded border border-border-primary bg-white p-6 text-sm text-charcoal/70">
                    No class-5 (trésorerie) movement in this window — no cash-flow statement is derivable.
                </div>
            @else
                <div class="overflow-x-auto rounded border border-border-primary bg-white print-block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Code</th>
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Compte de trésorerie</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Solde ouverture</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Encaissements</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Décaissements</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Solde clôture</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            @foreach ($flux['lines'] as $line)
                                <tr>
                                    <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['code'] }}</td>
                                    <td class="px-4 py-2 text-charcoal">{{ $line['name'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['opening']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['inflow']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['outflow']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['closing']) }}</td>
                                </tr>
                            @endforeach
                            <tr class="bg-chrome/10">
                                <td colspan="2" class="px-4 py-2.5 text-right text-sm font-semibold text-charcoal">TOTAL TRÉSORERIE</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-bold text-charcoal">{{ $fmt($flux['opening']) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-bold text-charcoal">{{ $fmt($flux['inflows']) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-bold text-charcoal">{{ $fmt($flux['outflows']) }}</td>
                                <td class="px-4 py-2.5 text-right font-mono text-sm font-bold text-charcoal">{{ $fmt($flux['closing']) }}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        @if ($tab === 'comparative' && $comparative !== null)
            @if (! $comparative['has_data'])
                <div class="rounded border border-border-primary bg-white p-6 text-sm text-charcoal/70">
                    Neither the selected window nor the prior equivalent window carries posted movement — there is nothing
                    to compare.
                </div>
            @else
                <div class="overflow-x-auto rounded border border-border-primary bg-white print-block">
                    <table class="min-w-full text-sm">
                        <thead>
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-left text-xs font-semibold uppercase tracking-wide">Ligne</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ $comparative['current_label'] }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ $comparative['prior_label'] }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Écart</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Écart %</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            @foreach ($comparative['rows'] as $line)
                                <tr class="{{ $line['emphasis'] ? 'bg-chrome/10 font-semibold' : '' }}">
                                    <td class="px-4 py-2 text-charcoal">{{ $line['label'] }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['current']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $fmt($line['prior']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono {{ $line['variance'] < 0 ? 'text-red-700' : 'text-charcoal' }}">{{ $fmt($line['variance']) }}</td>
                                    <td class="px-4 py-2 text-right font-mono text-charcoal/70">
                                        {{ $line['variance_pct'] === null ? '—' : number_format($line['variance_pct'], 1).'%' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="text-xs text-charcoal/60">
                    A dash in Écart % means the prior figure is zero — a percentage against a zero base would be an
                    invented number, so none is shown.
                </p>
            @endif
        @endif
    @endif
</div>
