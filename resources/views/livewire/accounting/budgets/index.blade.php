@php
    use App\Support\Money\Money;

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
            <p class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.budgets_screen.breadcrumb') }}</p>
            <h1 class="text-xl font-semibold text-charcoal">Budget &amp; Budget vs Actual</h1>
        </div>

        @if ($tab === 'variance')
            <div class="flex items-center gap-2 no-print">
                <button type="button" wire:click="exportExcel"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    Print
                </button>
            </div>
        @endif
    </div>

    @if (session('budget_status'))
        <div class="rounded border border-primary/40 bg-primary/5 px-3 py-2 text-sm text-charcoal">
            {{ session('budget_status') }}
        </div>
    @endif

    @error('budget')
        <div class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $message }}</div>
    @enderror

    {{-- ── Filters ─────────────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-end gap-3 rounded border border-sand bg-white p-3 no-print">
        <label for="bd-fiscal-year" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.fiscal_year') }}</span>
            <select id="bd-fiscal-year" wire:model.live="fiscalYearId"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">Select…</option>
                @foreach ($fiscalYearOptions as $year)
                    <option value="{{ $year->id }}">{{ $year->code }}</option>
                @endforeach
            </select>
        </label>

        <label for="bd-budget" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.budget') }}</span>
            <select id="bd-budget" wire:model.live="budgetId"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.budgets_screen.current_approved') }}</option>
                @foreach ($budgets as $budget)
                    <option value="{{ $budget->id }}">{{ $budget->code }} v{{ $budget->version }} — {{ $budget->status->label() }}</option>
                @endforeach
            </select>
        </label>

        @if ($tab === 'variance')
            <label for="bd-period-from" class="flex min-w-[9rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.period_from') }}</span>
                <select id="bd-period-from" wire:model.live="periodFrom"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">{{ __('opes.budgets_screen.year_start') }}</option>
                    @foreach ($periodMonths as $month)
                        <option value="{{ $month }}">{{ \Illuminate\Support\Carbon::parse($month)->format('Y-m') }}</option>
                    @endforeach
                </select>
            </label>

            <label for="bd-period-to" class="flex min-w-[9rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.period_to') }}</span>
                <select id="bd-period-to" wire:model.live="periodTo"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">{{ __('opes.budgets_screen.year_end') }}</option>
                    @foreach ($periodMonths as $month)
                        <option value="{{ $month }}">{{ \Illuminate\Support\Carbon::parse($month)->format('Y-m') }}</option>
                    @endforeach
                </select>
            </label>

            <label for="bd-account" class="flex min-w-[14rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.account') }}</span>
                <select id="bd-account" wire:model.live="accountFilterId"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">{{ __('opes.budgets_screen.all_budgeted_accounts') }}</option>
                    @foreach ($accountOptions as $account)
                        <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                    @endforeach
                </select>
            </label>

            <label for="bd-by-period" class="flex items-center gap-2 pb-1.5 text-sm text-charcoal">
                <input id="bd-by-period" type="checkbox" wire:model.live="byPeriod" class="rounded border-sand">
                <span>{{ __('opes.budgets_screen.break_down_by_period') }}</span>
            </label>
        @endif

        <div class="ml-auto flex items-center gap-2">
            <button type="button" wire:click="resetFilters"
                    class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Reset
            </button>
        </div>
    </div>

    {{-- ── Tabs ────────────────────────────────────────────────────────── --}}
    <div class="flex items-center gap-1 border-b border-sand no-print">
        @foreach ([['budgets', 'Budgets'], ['variance', 'Budget vs Actual']] as [$value, $label])
            <button type="button" wire:click="selectTab('{{ $value }}')"
                    class="border-b-2 px-3 py-2 text-sm font-medium {{ $tab === $value ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $label }}
            </button>
        @endforeach
    </div>

    @if ($tab === 'budgets')
        {{-- ── Budget list ─────────────────────────────────────────────── --}}
        <section class="space-y-3">
            <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                <table class="w-full min-w-[48rem] border-collapse text-sm">
                    <thead class="border-b border-sand bg-sand/40 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.code') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.name') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.version') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.status') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.current') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('opes.budgets_screen.lines') }}</th>
                            <th class="px-3 py-2 text-right font-medium">Budgeted (FCFA)</th>
                            <th class="px-3 py-2 font-medium no-print">{{ __('opes.budgets_screen.actions') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @forelse ($budgets as $budget)
                            <tr class="{{ (string) $budget->id === $budgetId ? 'bg-primary/5' : '' }}">
                                <td class="px-3 py-2 font-medium">{{ $budget->code }}</td>
                                <td class="px-3 py-2">{{ $budget->name }}</td>
                                <td class="px-3 py-2">v{{ $budget->version }}</td>
                                <td class="px-3 py-2">{{ $budget->status->label() }}</td>
                                <td class="px-3 py-2">{{ $budget->is_current ? 'Yes' : '—' }}</td>
                                <td class="px-3 py-2 text-right">{{ $budget->lines_count }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt((int) ($budget->budgeted_total ?? 0)) }}</td>
                                <td class="px-3 py-2 no-print">
                                    <div class="flex flex-wrap gap-2">
                                        <button type="button" wire:click="selectBudget({{ $budget->id }})"
                                                class="text-primary hover:underline">{{ __('opes.budgets_screen.open') }}</button>
                                        @if ($budget->status->isEditable())
                                            <button type="button" wire:click="approve({{ $budget->id }})"
                                                    class="text-primary hover:underline">{{ __('opes.budgets_screen.approve') }}</button>
                                        @else
                                            <button type="button" wire:click="revise({{ $budget->id }})"
                                                    class="text-primary hover:underline">{{ __('opes.budgets_screen.revise') }}</button>
                                        @endif
                                        @if ($budget->status->value === 'approved')
                                            <button type="button" wire:click="close({{ $budget->id }})"
                                                    class="text-charcoal/70 hover:underline">{{ __('opes.budgets_screen.close') }}</button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-6 text-center text-charcoal/60">
                                    No budget for this fiscal year yet. Create one below — nothing is enforced until a budget is approved.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            {{-- ── New budget ──────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white p-3 no-print">
                <h2 class="mb-2 text-sm font-semibold text-charcoal">New budget (draft)</h2>
                <div class="flex flex-wrap items-end gap-3">
                    <label for="bd-form-code" class="flex min-w-[10rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.code') }}</span>
                        <input id="bd-form-code" type="text" wire:model="formCode"
                               class="rounded border border-sand px-2 py-1.5 text-sm">
                    </label>
                    <label for="bd-form-name" class="flex min-w-[16rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.name') }}</span>
                        <input id="bd-form-name" type="text" wire:model="formName"
                               class="rounded border border-sand px-2 py-1.5 text-sm">
                    </label>
                    <label for="bd-form-notes" class="flex min-w-[16rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.notes') }}</span>
                        <input id="bd-form-notes" type="text" wire:model="formNotes"
                               class="rounded border border-sand px-2 py-1.5 text-sm">
                    </label>
                    <button type="button" wire:click="createBudget"
                            class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Create draft
                    </button>
                </div>
            </div>

            {{-- ── Line editor ─────────────────────────────────────────── --}}
            @if ($selectedBudget !== null)
                <div class="rounded border border-sand bg-white p-3">
                    <h2 class="mb-2 text-sm font-semibold text-charcoal">
                        {{ $selectedBudget->code }} v{{ $selectedBudget->version }} — {{ $selectedBudget->name }}
                        <span class="ml-2 text-xs font-normal text-charcoal/60">{{ $selectedBudget->status->label() }}</span>
                    </h2>

                    <div class="min-w-0 overflow-x-auto">
                        <table class="w-full min-w-[52rem] border-collapse text-sm">
                            <thead class="border-b border-sand bg-sand/40 text-left">
                                <tr>
                                    <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.account') }}</th>
                                    <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.analytic') }}</th>
                                    <th class="px-3 py-2 text-right font-medium">Annual (FCFA)</th>
                                    <th class="px-3 py-2 text-right font-medium">Phased (FCFA)</th>
                                    <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.phasing') }}</th>
                                    <th class="px-3 py-2 font-medium no-print">{{ __('opes.budgets_screen.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand">
                                @forelse ($budgetLines as $line)
                                    <tr>
                                        <td class="px-3 py-2">
                                            <span class="font-medium">{{ $line->account?->code }}</span>
                                            <span class="text-charcoal/70"> — {{ $line->account?->name }}</span>
                                        </td>
                                        <td class="px-3 py-2">{{ $line->analyticValue?->code ?? '—' }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums">{{ $fmt((int) $line->annual_amount) }}</td>
                                        <td class="px-3 py-2 text-right tabular-nums {{ (int) ($line->phased_total ?? 0) === (int) $line->annual_amount ? '' : 'text-red-700 font-semibold' }}">
                                            {{ $fmt((int) ($line->phased_total ?? 0)) }}
                                        </td>
                                        <td class="px-3 py-2 text-xs text-charcoal/70">
                                            {{ $line->phasings->count() }} periods
                                        </td>
                                        <td class="px-3 py-2 no-print">
                                            @if ($selectedBudget->status->isEditable())
                                                <div class="flex flex-wrap gap-2">
                                                    <button type="button" wire:click="editLine({{ $line->id }})"
                                                            class="text-primary hover:underline">{{ __('opes.budgets_screen.edit') }}</button>
                                                    <button type="button" wire:click="rephaseLine({{ $line->id }}, 'equal')"
                                                            class="text-charcoal/70 hover:underline">{{ __('opes.budgets_screen.equal') }}</button>
                                                    <button type="button" wire:click="rephaseLine({{ $line->id }}, 'academic_calendar')"
                                                            class="text-charcoal/70 hover:underline">{{ __('opes.budgets_screen.academic') }}</button>
                                                    <button type="button" wire:click="deleteLine({{ $line->id }})"
                                                            class="text-red-700 hover:underline">{{ __('opes.budgets_screen.remove') }}</button>
                                                </div>
                                            @else
                                                <span class="text-xs text-charcoal/50">Immutable (B-2)</span>
                                            @endif
                                        </td>
                                    </tr>

                                    @if ($line->phasings->isNotEmpty())
                                        <tr class="bg-sand/20">
                                            <td colspan="6" class="px-3 py-1.5">
                                                <div class="flex flex-wrap gap-x-4 gap-y-1 text-xs text-charcoal/70">
                                                    @foreach ($line->phasings as $phasing)
                                                        <span>{{ $phasing->period_month->format('Y-m') }}: <span class="tabular-nums">{{ $fmt((int) $phasing->amount) }}</span></span>
                                                    @endforeach
                                                </div>
                                            </td>
                                        </tr>
                                    @endif
                                @empty
                                    <tr>
                                        <td colspan="6" class="px-3 py-6 text-center text-charcoal/60">
                                            No lines yet. A budget with no lines cannot be approved.
                                        </td>
                                    </tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>

                    @if ($selectedBudget->status->isEditable())
                        <div class="mt-3 space-y-3 border-t border-sand pt-3 no-print">
                            <div class="flex flex-wrap items-end gap-3">
                                <label for="bd-line-account" class="flex min-w-[16rem] flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">Account (class 2, 6 or 7, postable)</span>
                                    <select id="bd-line-account" wire:model="lineAccountId"
                                            class="rounded border border-sand bg-white px-2 py-1.5 text-sm">
                                        <option value="">Select…</option>
                                        @foreach ($accountOptions as $account)
                                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label for="bd-line-analytic" class="flex min-w-[12rem] flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">Analytic value (optional)</span>
                                    <select id="bd-line-analytic" wire:model="lineAnalyticValueId"
                                            class="rounded border border-sand bg-white px-2 py-1.5 text-sm">
                                        <option value="">{{ __('opes.budgets_screen.none') }}</option>
                                        @foreach ($analyticValueOptions as $value)
                                            <option value="{{ $value->id }}">{{ $value->code }} — {{ $value->name }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label for="bd-line-annual" class="flex min-w-[10rem] flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">Annual amount (FCFA)</span>
                                    <input id="bd-line-annual" type="number" wire:model="lineAnnualAmount"
                                           class="rounded border border-sand px-2 py-1.5 text-sm">
                                </label>

                                <label for="bd-line-profile" class="flex min-w-[16rem] flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.phasing') }}</span>
                                    <select id="bd-line-profile" wire:model.live="linePhasingProfile"
                                            class="rounded border border-sand bg-white px-2 py-1.5 text-sm">
                                        @foreach ($phasingProfiles as $profile)
                                            <option value="{{ $profile->value }}">{{ $profile->label() }}</option>
                                        @endforeach
                                    </select>
                                </label>

                                <label for="bd-line-notes" class="flex min-w-[16rem] flex-col gap-1">
                                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.budgets_screen.notes') }}</span>
                                    <input id="bd-line-notes" type="text" wire:model="lineNotes"
                                           class="rounded border border-sand px-2 py-1.5 text-sm">
                                </label>

                                <button type="button" wire:click="saveLine"
                                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                    {{ $editingLineId === null ? 'Add line' : 'Update line' }}
                                </button>

                                @if ($editingLineId !== null)
                                    <button type="button" wire:click="resetLineForm"
                                            class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal">
                                        Cancel
                                    </button>
                                @endif
                            </div>

                            @if ($linePhasingProfile === 'manual')
                                <div class="grid grid-cols-2 gap-2 sm:grid-cols-4 lg:grid-cols-6">
                                    @foreach ($periodMonths as $month)
                                        <label for="bd-phase-{{ $month }}" class="flex flex-col gap-1">
                                            <span class="text-xs text-charcoal/60">{{ \Illuminate\Support\Carbon::parse($month)->format('Y-m') }}</span>
                                            <input id="bd-phase-{{ $month }}" type="number"
                                                   wire:model="lineManualAmounts.{{ $month }}"
                                                   class="rounded border border-sand px-2 py-1 text-sm">
                                        </label>
                                    @endforeach
                                </div>
                                <p class="text-xs text-charcoal/60">
                                    Manual phasing must sum exactly to the annual amount (§16 B-1); the Action refuses anything else.
                                </p>
                            @endif
                        </div>
                    @endif
                </div>
            @endif
        </section>
    @else
        {{-- ── Budget vs Actual ────────────────────────────────────────── --}}
        <section class="space-y-3 print-block">
            <p class="text-xs text-charcoal/60">
                Actuals are read through the posted ledger scope (posted + reversed, §4.3 L13), grouped by accounting period.
                Charges and capex are debit − credit; produits are credit − debit, so a positive variance means over-spent on a
                charge and over-earned on a produit — the reading column says which.
            </p>

            <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                <table class="w-full min-w-[52rem] border-collapse text-sm">
                    <thead class="border-b border-sand bg-sand/40 text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.code') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.account') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.period') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('opes.budgets_screen.budget') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('opes.budgets_screen.actual') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('opes.budgets_screen.variance') }}</th>
                            <th class="px-3 py-2 text-right font-medium">{{ __('opes.budgets_screen.variance_pct') }}</th>
                            <th class="px-3 py-2 font-medium">{{ __('opes.budgets_screen.control') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        @forelse ($varianceRows as $row)
                            <tr>
                                <td class="px-3 py-2 font-medium">{{ $row['code'] }}</td>
                                <td class="px-3 py-2">{{ $row['name'] }}</td>
                                <td class="px-3 py-2">{{ $row['period_month'] === 'Total' ? 'Year to date' : \Illuminate\Support\Carbon::parse($row['period_month'])->format('Y-m') }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($row['budget']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums">{{ $fmt($row['actual']) }}</td>
                                <td class="px-3 py-2 text-right tabular-nums {{ $row['favourable'] ? 'text-emerald-700' : 'text-red-700' }}">
                                    {{ $fmt($row['variance']) }}
                                </td>
                                <td class="px-3 py-2 text-right tabular-nums">
                                    {{ $row['variance_pct'] === null ? 'n/a' : $row['variance_pct'].'%' }}
                                </td>
                                <td class="px-3 py-2 text-xs uppercase tracking-wide text-charcoal/60">{{ $row['budget_control'] }}</td>
                            </tr>
                        @empty
                            <tr>
                                <td colspan="8" class="px-3 py-6 text-center text-charcoal/60">
                                    Nothing to compare: this fiscal year has no approved budget, and no class 2/6/7 movement in the selected range.
                                </td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
