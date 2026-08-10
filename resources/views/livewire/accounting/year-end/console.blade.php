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
            <p class="text-xs uppercase tracking-wide text-charcoal/50">Dashboard / Ledger / Year-end</p>
            <h1 class="text-xl font-semibold text-charcoal">Year-End Console (OHADA §17 / §18)</h1>
            <p class="mt-1 text-xs text-charcoal/60">
                The thirteen-step close sequence, its sign-offs and its waivers. Every step refuses rather than guesses.
            </p>
        </div>

        <div class="flex items-center gap-2 no-print">
            <label for="ye-fiscal-year" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
                <select id="ye-fiscal-year" wire:model.live="fiscalYearId"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    @foreach ($fiscalYears as $year)
                        <option value="{{ $year->id }}">{{ $year->code }} ({{ $year->status->value }})</option>
                    @endforeach
                </select>
            </label>

            <button type="button" wire:click="exportPdf"
                    class="self-end rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Checklist PDF
            </button>
            <button type="button" onclick="window.print()"
                    class="self-end rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                Print
            </button>
        </div>
    </div>

    @if ($statusMessage !== '')
        <p class="rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">{{ $statusMessage }}</p>
    @endif

    @if ($errorMessage !== '')
        <p class="rounded border border-red-300 bg-red-50 px-3 py-2 text-sm text-red-800">{{ $errorMessage }}</p>
    @endif

    @if ($fiscalYear === null || $state === null)
        <p class="rounded border border-sand bg-white p-4 text-sm text-charcoal/70">
            No fiscal year selected. Create one before running a close.
        </p>
    @else
        <div class="grid gap-4 lg:grid-cols-3">
            <div class="rounded border border-sand bg-white p-3 print-block">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">Exercice</p>
                <p class="text-lg font-semibold text-charcoal">{{ $fiscalYear->code }}</p>
                <p class="text-xs text-charcoal/60">
                    {{ $fiscalYear->starts_on->toDateString() }} &rarr; {{ $fiscalYear->ends_on->toDateString() }}
                    &middot; {{ $fiscalYear->status->value }}
                </p>
                <p class="mt-1 text-xs text-charcoal/60">
                    Next exercice: {{ $state['next_fiscal_year']?->code ?? 'not created' }}
                </p>
            </div>

            <div class="rounded border border-sand bg-white p-3 print-block">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">Compte 13 - result pending appropriation</p>
                <p class="text-lg font-semibold {{ $resultBalance >= 0 ? 'text-emerald-700' : 'text-red-700' }}">
                    {{ $fmt($resultBalance) }}
                </p>
                <p class="text-xs text-charcoal/60">Positive is a profit; it is read from the ledger, never keyed.</p>
            </div>

            <div class="rounded border border-sand bg-white p-3 print-block">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">Checklist</p>
                <p class="text-lg font-semibold text-charcoal">{{ $state['checklist']->status->value }}</p>
                <p class="text-xs {{ $state['can_close'] ? 'text-emerald-700' : 'text-charcoal/60' }}">
                    {{ $state['can_close'] ? 'Ready to close.' : 'Not closable yet - see the blockers.' }}
                </p>
            </div>
        </div>

        @if ($state['blockers'] !== [])
            <div class="rounded border border-amber-300 bg-amber-50 p-3 print-block">
                <p class="text-sm font-semibold text-amber-900">Blockers - structural, and not waivable</p>
                <ul class="mt-2 space-y-1">
                    @foreach ($state['blockers'] as $blocker)
                        <li class="text-sm text-amber-900">
                            <span class="font-mono text-xs">[{{ $blocker['code'] }}]</span>
                            {{ $blocker['message'] }}
                        </li>
                    @endforeach
                </ul>
            </div>
        @endif

        <div class="rounded border border-sand bg-white print-block">
            <div class="flex items-center justify-between border-b border-sand px-3 py-2">
                <h2 class="text-sm font-semibold text-charcoal">Step 7 - the §17.9 trial-balance validation</h2>
                <span class="text-xs {{ $state['validation']['passed'] ? 'text-emerald-700' : 'text-red-700' }}">
                    {{ $state['validation']['passed'] ? 'PASS' : 'FAIL' }}
                    &middot; checked {{ $state['validation']['checked_at'] }}
                </span>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-sand text-left text-xs uppercase tracking-wide text-charcoal/50">
                        <th class="px-3 py-2">Check</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2 text-right">Offending rows</th>
                        <th class="px-3 py-2 no-print"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($state['validation']['checks'] as $check)
                        <tr class="border-b border-sand/60">
                            <td class="px-3 py-2 text-charcoal">{{ $check['label'] }}</td>
                            <td class="px-3 py-2">
                                <span class="rounded px-2 py-0.5 text-xs font-medium
                                    @if ($check['status'] === $failStatus) bg-red-100 text-red-800
                                    @elseif ($check['status'] === $unavailableStatus) bg-amber-100 text-amber-800
                                    @else bg-emerald-100 text-emerald-800 @endif">
                                    {{ $check['status'] }}
                                </span>
                            </td>
                            <td class="px-3 py-2 text-right text-charcoal/70">{{ count($check['failures']) }}</td>
                            <td class="px-3 py-2 text-right no-print">
                                @if ($check['failures'] !== [])
                                    <button type="button" wire:click="toggleCheck('{{ $check['code'] }}')"
                                            class="text-xs text-primary hover:underline">
                                        {{ $expandedCheck === $check['code'] ? 'Hide' : 'Show' }}
                                    </button>
                                @endif
                            </td>
                        </tr>

                        @if ($expandedCheck === $check['code'])
                            <tr class="border-b border-sand/60 bg-sand/20">
                                <td colspan="4" class="px-3 py-2">
                                    <div class="max-h-64 overflow-auto">
                                        <table class="w-full text-xs">
                                            <tbody>
                                                @foreach (array_slice($check['failures'], 0, 200) as $failure)
                                                    <tr>
                                                        <td class="py-0.5 font-mono text-charcoal/70">
                                                            {{ collect($failure)->map(fn ($v, $k) => $k.'='.$v)->implode('  ') }}
                                                        </td>
                                                    </tr>
                                                @endforeach
                                            </tbody>
                                        </table>
                                        @if (count($check['failures']) > 200)
                                            <p class="pt-1 text-xs text-charcoal/50">
                                                … {{ count($check['failures']) - 200 }} more.
                                            </p>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rounded border border-sand bg-white print-block">
            <div class="border-b border-sand px-3 py-2">
                <h2 class="text-sm font-semibold text-charcoal">The close sequence (§17.2)</h2>
            </div>

            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-sand text-left text-xs uppercase tracking-wide text-charcoal/50">
                        <th class="px-3 py-2">#</th>
                        <th class="px-3 py-2">Step</th>
                        <th class="px-3 py-2">Status</th>
                        <th class="px-3 py-2">Evidence / waiver</th>
                        <th class="px-3 py-2 no-print">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($state['items'] as $item)
                        <tr class="border-b border-sand/60 align-top">
                            <td class="px-3 py-2 text-charcoal/60">{{ $item->sequence }}</td>
                            <td class="px-3 py-2">
                                <p class="text-charcoal">{{ $item->title }}</p>
                                <p class="text-xs text-charcoal/50">{{ $item->title_fr }}</p>
                            </td>
                            <td class="px-3 py-2">
                                <span class="rounded px-2 py-0.5 text-xs font-medium
                                    @if ($item->status->value === 'completed') bg-emerald-100 text-emerald-800
                                    @elseif ($item->status->value === 'waived') bg-amber-100 text-amber-800
                                    @else bg-sand/60 text-charcoal/70 @endif">
                                    {{ $item->status->value }}
                                </span>
                                @if ($item->is_automated)
                                    <span class="ml-1 text-[10px] uppercase text-charcoal/40">auto</span>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-xs text-charcoal/70">
                                @if ($item->evidence_type !== null)
                                    <span class="font-mono">{{ $item->evidence_type }}#{{ $item->evidence_id }}</span>
                                @endif
                                @if ($item->waiver_reason !== null)
                                    <p class="text-amber-800">Waived: {{ $item->waiver_reason }}</p>
                                @endif
                                @if ($item->completed_at !== null)
                                    <p>{{ $item->completed_at->format('Y-m-d H:i') }}</p>
                                @endif
                            </td>
                            <td class="px-3 py-2 no-print">
                                <div class="flex flex-wrap gap-2">
                                    @if (array_key_exists($item->code, $runnableSteps) && $item->status->value !== 'completed')
                                        <button type="button" wire:click="{{ $runnableSteps[$item->code] }}"
                                                class="rounded border border-primary bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90">
                                            Run step
                                        </button>
                                    @endif

                                    @if (! $item->is_automated && $item->status->value === 'pending')
                                        <button type="button" wire:click="completeItem({{ $item->id }})"
                                                class="rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                            Sign off
                                        </button>
                                    @endif

                                    @if ($item->status->value === 'pending')
                                        <button type="button" wire:click="startWaiver({{ $item->id }})"
                                                class="rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-amber-400 hover:text-amber-700">
                                            Waive
                                        </button>
                                    @endif
                                </div>

                                @if ($waivingItemId === (string) $item->id)
                                    <div class="mt-2 space-y-1">
                                        <label class="flex flex-col gap-1">
                                            <span class="text-xs text-charcoal/60">Reason (20 characters minimum, YE-2)</span>
                                            <textarea wire:model="waiverReason" rows="2"
                                                      class="w-full rounded border border-sand px-2 py-1 text-xs"></textarea>
                                        </label>
                                        <div class="flex gap-2">
                                            <button type="button" wire:click="waiveItem"
                                                    class="rounded border border-amber-400 bg-amber-50 px-2 py-1 text-xs font-medium text-amber-800">
                                                Record waiver
                                            </button>
                                            <button type="button" wire:click="cancelWaiver"
                                                    class="rounded border border-sand px-2 py-1 text-xs text-charcoal/70">
                                                Cancel
                                            </button>
                                        </div>
                                    </div>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <div class="rounded border border-sand bg-white print-block">
            <div class="border-b border-sand px-3 py-2">
                <h2 class="text-sm font-semibold text-charcoal">Step 10 - result appropriation (§18.3)</h2>
                <p class="text-xs text-charcoal/60">
                    Keyed from the resolution. The legal-reserve percentage is not computed by the product.
                </p>
            </div>

            <div class="space-y-3 p-3">
                @if ($appropriation !== null)
                    <p class="text-xs text-charcoal/70">
                        Recorded: {{ $appropriation->decision_body }} &middot; {{ $appropriation->decision_date->toDateString() }}
                        &middot; {{ $appropriation->resolution_reference }} &middot; {{ $appropriation->status }}
                        &middot; result {{ $fmt((int) $appropriation->result_amount) }}
                    </p>
                    <ul class="text-xs text-charcoal/70">
                        @foreach ($appropriation->lines as $line)
                            <li>{{ $line->label }} - {{ $fmt((int) $line->amount) }}</li>
                        @endforeach
                    </ul>
                @endif

                <div class="grid gap-2 md:grid-cols-3 no-print">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-charcoal/60">Deciding body</span>
                        <input type="text" wire:model="decisionBody" class="rounded border border-sand px-2 py-1 text-sm">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-charcoal/60">Decision date</span>
                        <input type="date" wire:model="decisionDate" class="rounded border border-sand px-2 py-1 text-sm">
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs text-charcoal/60">Resolution reference</span>
                        <input type="text" wire:model="resolutionReference" class="rounded border border-sand px-2 py-1 text-sm">
                    </label>
                </div>

                <div class="space-y-2 no-print">
                    @foreach ($appropriationLines as $index => $line)
                        <div class="grid gap-2 md:grid-cols-3">
                            <select wire:model="appropriationLines.{{ $index }}.account_id"
                                    class="rounded border border-sand px-2 py-1 text-sm">
                                <option value="">Account…</option>
                                @foreach ($equityAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} - {{ $account->name }}</option>
                                @endforeach
                            </select>
                            <input type="number" wire:model="appropriationLines.{{ $index }}.amount"
                                   placeholder="Amount (signed FCFA)"
                                   class="rounded border border-sand px-2 py-1 text-sm">
                            <input type="text" wire:model="appropriationLines.{{ $index }}.label"
                                   placeholder="Label"
                                   class="rounded border border-sand px-2 py-1 text-sm">
                        </div>
                    @endforeach

                    <div class="flex gap-2">
                        <button type="button" wire:click="addAppropriationLine"
                                class="rounded border border-sand px-2 py-1 text-xs text-charcoal/70">
                            Add line
                        </button>
                        <button type="button" wire:click="saveAppropriation"
                                class="rounded border border-primary bg-primary px-3 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Save draft appropriation
                        </button>
                    </div>
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 rounded border border-sand bg-white p-3 no-print">
            <button type="button" wire:click="closeYear"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                Close fiscal year {{ $fiscalYear->code }}
            </button>
            <p class="text-xs text-charcoal/60">
                YE-1: refused while any mandatory step is pending. Hard-locking the periods is step 13 and is done per
                period, deliberately not by this button.
            </p>
        </div>
    @endif
</div>
