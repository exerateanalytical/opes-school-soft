{{-- docs/specs/02-accounting.md §13 - relevé on the left, books on the right,
     the état de rapprochement underneath. ONE root element: a second one
     silently breaks Livewire morphing, which has bitten this codebase before.
     Strings are literal English on purpose - lang/en|fr/opes.php is under
     concurrent edit and this screen adds no keys to it. --}}
<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @foreach (['session', 'match', 'close', 'import'] as $bucket)
        @error($bucket)
            <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
                {{ $message }}
            </p>
        @enderror
    @endforeach

    <section aria-label="Treasury float and period" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h1 class="text-base font-semibold text-charcoal">{{ __('opes.reconciliation_screen.title') }}</h1>
        <p class="mt-1 text-xs text-charcoal/60">
            Every postable class-5 account flagged reconcilable gets its own reconciliation - the bank account,
            and the MTN and Orange floats separately, each against its own operator statement. A cash box is
            counted at the desk, not reconciled here.
        </p>

        <div class="mt-4 flex flex-wrap gap-2">
            @foreach ($accounts as $option)
                <button type="button" wire:click="selectAccount({{ $option->id }})"
                        class="rounded border px-3 py-1.5 text-sm {{ $accountId === $option->id ? 'border-primary bg-primary/10 font-semibold text-primary' : 'border-border-primary text-charcoal' }}">
                    {{ $option->code }} — {{ $option->display_alias ?? $option->name }}
                </button>
            @endforeach
        </div>

        <label for="recon-period" class="mt-4 flex max-w-xs flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.accounting_period') }}</span>
            <select id="recon-period" wire:model.live="periodId"
                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                @foreach ($periodOptions as $option)
                    <option value="{{ $option->id }}">
                        {{ $option->starts_on->format('M Y') }} ({{ $option->status->value }})
                    </option>
                @endforeach
            </select>
        </label>

        @php
            // A disabled control must say WHY it is disabled, and when two
            // reasons can apply at once the operator must be able to tell them
            // apart ("no permission" is not the same as "nothing imported").
            $whyNoPost = __('opes.reconciliation_screen.why_no_post');
            $whyNoStatement = __('opes.reconciliation_screen.why_no_statement');
            $whyAutoMatch = ! $canPost ? $whyNoPost : ($statement === null ? $whyNoStatement : null);
        @endphp

        <div class="mt-4 flex flex-wrap items-center gap-2">
            @if ($session === null)
                <button type="button" wire:click="openSession" @disabled(! $canPost)
                        @if (! $canPost) title="{{ $whyNoPost }}" @endif
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
                    Open reconciliation
                </button>
            @else
                <span class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                    {{ $session->session_no }} — {{ $session->status->label() }}
                </span>

                @if ($session->isDraft())
                    <button type="button" wire:click="autoMatch" @disabled($whyAutoMatch !== null)
                            @if ($whyAutoMatch !== null) title="{{ $whyAutoMatch }}" @endif
                            class="rounded border border-primary px-3 py-1.5 text-sm font-semibold text-primary disabled:opacity-50">
                        Auto-match
                    </button>
                    <button type="button" wire:click="closeSession" @disabled(! $canPost)
                            @if (! $canPost) title="{{ $whyNoPost }}" @endif
                            class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
                        Complete reconciliation
                    </button>
                @endif

                <button type="button" wire:click="exportPdf"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                    Print état de rapprochement (PDF)
                </button>
            @endif

            <button type="button" wire:click="toggleImportForm" @disabled(! $canPost)
                    @if (! $canPost) title="{{ $whyNoPost }}" @endif
                    class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal disabled:opacity-50">
                {{ $showImportForm ? __('opes.reconciliation_screen.cancel_import') : __('opes.reconciliation_screen.import_statement') }}
            </button>
        </div>
    </section>

    @if ($showImportForm)
        <section aria-label="{{ __('opes.reconciliation_screen.import_statement') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.reconciliation_screen.import_statement') }}</h2>
            <p class="mt-1 text-xs text-charcoal/60">
                CSV columns, in this order and with this header row:
                <code>operation_date,value_date,label,reference,debit,credit</code>.
                Amounts are whole FCFA; <em>credit</em> is money into the account. The import is refused unless
                the lines add up to the movement between the two balances below.
            </p>

            <form wire:submit="importStatement" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.statement_reference') }}</span>
                        <input type="text" wire:model="importReference"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.period_start') }}</span>
                        <input type="date" wire:model="importPeriodStart"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.period_end') }}</span>
                        <input type="date" wire:model="importPeriodEnd"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.opening_balance') }}</span>
                        <input type="number" wire:model="importOpeningBalance"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.reconciliation_screen.closing_balance') }}</span>
                        <input type="number" wire:model="importClosingBalance"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                    </label>
                </div>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">CSV</span>
                    <textarea wire:model="importCsv" rows="6"
                              class="rounded border border-border-primary bg-white px-3 py-1.5 font-mono text-xs text-charcoal"></textarea>
                </label>

                <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                    Import
                </button>
            </form>
        </section>
    @endif

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-2">
        <section aria-label="Statement lines" class="min-w-0 rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold text-charcoal">
                The relevé
                @if ($statement)
                    <span class="text-xs font-normal text-charcoal/60">
                        — {{ $statement->statement_reference }},
                        closing {{ number_format($statement->closing_balance) }} FCFA
                    </span>
                @endif
            </h2>

            @if ($statement === null)
                <p class="mt-3 text-sm text-charcoal/60">No statement imported for this float and period yet.</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[36rem] text-left text-sm">
                        <thead class="text-xs uppercase text-charcoal/60">
                            <tr>
                                <th class="py-1 pr-2"></th>
                                <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.date') }}</th>
                                <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.label') }}</th>
                                <th class="py-1 pr-2 text-right">Out</th>
                                <th class="py-1 pr-2 text-right">In</th>
                                <th class="py-1">{{ __('opes.reconciliation_screen.status') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($statementLines as $line)
                                <tr class="border-t border-border-primary/60">
                                    <td class="py-1 pr-2">
                                        @if ($line->isAvailable() && $session?->isDraft())
                                            <input type="checkbox" value="{{ $line->id }}"
                                                   wire:model="selectedStatementLines"
                                                   aria-label="Select statement line {{ $line->line_no }}"/>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-2 whitespace-nowrap">{{ $line->operation_date->toDateString() }}</td>
                                    <td class="py-1 pr-2">
                                        {{ $line->label }}
                                        @if ($line->reference)
                                            <span class="text-xs text-charcoal/50">({{ $line->reference }})</span>
                                        @endif
                                    </td>
                                    <td class="py-1 pr-2 text-right">{{ $line->debit ? number_format($line->debit) : '' }}</td>
                                    <td class="py-1 pr-2 text-right">{{ $line->credit ? number_format($line->credit) : '' }}</td>
                                    <td class="py-1 text-xs">{{ $line->status->label() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>

        <section aria-label="Unreconciled ledger lines" class="min-w-0 rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <h2 class="text-base font-semibold text-charcoal">
                The books
                <span class="text-xs font-normal text-charcoal/60">— unmatched movements on this float</span>
            </h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[36rem] text-left text-sm">
                    <thead class="text-xs uppercase text-charcoal/60">
                        <tr>
                            <th class="py-1 pr-2"></th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.date') }}</th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.piece') }}</th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.label') }}</th>
                            <th class="py-1 pr-2 text-right">{{ __('opes.reconciliation_screen.debit') }}</th>
                            <th class="py-1 text-right">{{ __('opes.reconciliation_screen.credit') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($ledgerLines as $line)
                            <tr class="border-t border-border-primary/60">
                                <td class="py-1 pr-2">
                                    @if ($session?->isDraft())
                                        <input type="checkbox" value="{{ $line->id }}"
                                               wire:model="selectedLedgerLines"
                                               aria-label="Select ledger line {{ $line->id }}"/>
                                    @endif
                                </td>
                                <td class="py-1 pr-2 whitespace-nowrap">{{ $line->date }}</td>
                                <td class="py-1 pr-2 text-xs">{{ $line->piece_no }}</td>
                                <td class="py-1 pr-2">{{ $line->label }}</td>
                                <td class="py-1 pr-2 text-right">{{ $line->debit ? number_format($line->debit) : '' }}</td>
                                <td class="py-1 text-right">{{ $line->credit ? number_format($line->credit) : '' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="py-3 text-sm text-charcoal/60">Nothing unmatched on this float.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    </div>

    @if ($session?->isDraft())
        <div class="flex flex-wrap items-center gap-3">
            @php
                $whyMatch = ! $canPost
                    ? __('opes.reconciliation_screen.why_no_post')
                    : (($selectedStatementLines === [] || $selectedLedgerLines === [])
                        ? __('opes.reconciliation_screen.why_no_selection')
                        : null);
            @endphp
            <button type="button" wire:click="matchSelected"
                    @disabled($whyMatch !== null)
                    @if ($whyMatch !== null) title="{{ $whyMatch }}" @endif
                    class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white disabled:opacity-50">
                Match {{ count($selectedStatementLines) }} relevé line(s) to {{ count($selectedLedgerLines) }} ledger line(s)
            </button>
            <button type="button" wire:click="resetSelection" class="text-sm text-charcoal/60 underline">
                Clear selection
            </button>
        </div>
    @endif

    @if ($etat !== null)
        <section aria-label="Reconciliation statement" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">
                État de rapprochement
                <span class="text-xs font-normal text-charcoal/60">
                    — {{ $account?->code }} at {{ $period?->ends_on->toDateString() }}
                </span>
            </h2>

            <dl class="mt-3 max-w-xl space-y-1 text-sm">
                <div class="flex justify-between"><dt>{{ __('opes.reconciliation_screen.solde_releve') }}</dt><dd>{{ number_format($etat['statement_balance']) }}</dd></div>
                <div class="flex justify-between"><dt>+ Encaissements comptabilisés non encore au relevé</dt><dd>{{ number_format($etat['deposits_in_transit']) }}</dd></div>
                <div class="flex justify-between"><dt>− Décaissements comptabilisés non encore au relevé</dt><dd>({{ number_format($etat['unpresented_payments']) }})</dd></div>
                <div class="flex justify-between"><dt>− Opérations au relevé non encore comptabilisées</dt><dd>({{ number_format($etat['unrecorded_statement_items']) }})</dd></div>
                <div class="flex justify-between border-t border-border-primary pt-1 font-semibold"><dt>= Solde comptable</dt><dd>{{ number_format($etat['book_balance']) }}</dd></div>
                <div class="flex justify-between {{ $etat['computed_difference'] === 0 ? 'text-charcoal/60' : 'font-semibold text-heritage-red' }}">
                    <dt>{{ __('opes.reconciliation_screen.difference') }}</dt><dd>{{ number_format($etat['computed_difference']) }}</dd>
                </div>
            </dl>

            @unless ($etat['ties'])
                <p class="mt-3 text-xs text-heritage-red">
                    This reconciliation cannot be completed yet. Anything the bank recorded and the books did not -
                    a charge, an operator commission, a direct debit - must be posted, not reconciled away.
                </p>
            @endunless
        </section>
    @endif

    @if ($matches->isNotEmpty())
        <section aria-label="Matches" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.reconciliation_screen.matches') }}</h2>

            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[40rem] text-left text-sm">
                    <thead class="text-xs uppercase text-charcoal/60">
                        <tr>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.type') }}</th>
                            <th class="py-1 pr-2 text-right">{{ __('opes.reconciliation_screen.amount') }}</th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.releve') }}</th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.books') }}</th>
                            <th class="py-1 pr-2">{{ __('opes.reconciliation_screen.confidence') }}</th>
                            <th class="py-1"></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($matches as $row)
                            <tr class="border-t border-border-primary/60">
                                <td class="py-1 pr-2 text-xs">{{ $row->match_type }}{{ $row->is_auto ? ' (auto)' : '' }}</td>
                                <td class="py-1 pr-2 text-right">{{ number_format((int) $row->amount) }}</td>
                                <td class="py-1 pr-2">{{ $row->statement_lines }}</td>
                                <td class="py-1 pr-2">{{ $row->ledger_lines }}</td>
                                <td class="py-1 pr-2 text-xs">{{ number_format($row->confidence_bp / 100, 1) }}%</td>
                                <td class="py-1">
                                    @if ($session?->isDraft() && $canPost)
                                        <button type="button" wire:click="unmatch({{ $row->id }})"
                                                class="text-xs text-heritage-red underline">{{ __('opes.reconciliation_screen.unmatch') }}</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif
</div>
