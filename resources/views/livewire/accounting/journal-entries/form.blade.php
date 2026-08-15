@php
    use App\Support\Money\Money;

    $partnerTypeOptions = ['student', 'guardian', 'supplier', 'staff', 'organisation'];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.ledger_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>{{ __('opes.ledger_screen.breadcrumb_ledger') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span><span aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.ledger_screen.breadcrumb_je_form') }}</span></li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">
            {{ $draftEntryId === null ? __('opes.ledger_screen.je_form_title_new') : __('opes.ledger_screen.je_form_title_continue') }}
        </h1>
    </div>

    @include('livewire.accounting._ledger-subnav')

    @if ($statusMessage !== '')
        <div class="rounded border border-primary/40 bg-primary/10 px-4 py-3 text-sm text-primary" role="status">
            {{ $statusMessage }}
        </div>
    @endif

    @if ($errorMessage !== '')
        <div class="rounded border border-heritage-red/40 bg-heritage-red/10 px-4 py-3 text-sm text-heritage-red" role="alert">
            {{ $errorMessage }}
        </div>
    @endif

    {{-- Header --}}
    <section class="rounded border border-border-primary bg-white p-4">
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <label for="je-journal" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_journal_label') }}</span>
                <select id="je-journal" wire:model="journalId" @disabled($isLocked)
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40">
                    <option value="">{{ __('opes.ledger_screen.choose') }}</option>
                    @foreach ($journalOptions as $journal)
                        <option value="{{ $journal->id }}">{{ $journal->code }} — {{ $journal->name }}</option>
                    @endforeach
                </select>
                @error('journalId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>

            <label for="je-date" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_date_label') }}</span>
                <input id="je-date" type="date" wire:model="date" @disabled($isLocked)
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
                @error('date') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>

            <label for="je-value-date" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_value_date_label') }}</span>
                <input id="je-value-date" type="date" wire:model="valueDate" @disabled($isLocked)
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
            </label>

            <label for="je-reference" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_reference_label') }}</span>
                <input id="je-reference" type="text" wire:model="reference" @disabled($isLocked)
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
            </label>

            <label for="je-label" class="flex flex-col gap-1 sm:col-span-2 lg:col-span-4">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_label_label') }}</span>
                <input id="je-label" type="text" wire:model="label" @disabled($isLocked)
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
                @error('label') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
        </div>

        @if ($isLocked)
            <p class="mt-2 text-xs text-charcoal/60">
                {{ __('opes.ledger_screen.je_header_locked_note', ['piece' => $pieceNo !== '' ? $pieceNo : __('opes.ledger_screen.je_piece_draft_placeholder')]) }}
            </p>
        @endif
    </section>

    {{-- Lines --}}
    <section class="rounded border border-border-primary bg-white p-4">
        <h2 class="text-sm font-semibold text-charcoal">{{ __('opes.ledger_screen.je_lines_title') }}</h2>

        <div class="mt-3 space-y-3">
            @foreach ($lines as $index => $line)
                @php
                    $picked = $line['account_id'] !== '' ? $pickedAccounts->get((int) $line['account_id']) : null;
                    $isCollective = $picked?->is_collective ?? false;
                    $allowedPartnerTypes = $picked?->allowed_partner_types ?? $partnerTypeOptions;
                    $persisted = ($line['id'] ?? null) !== null;
                @endphp
                <div class="rounded border border-border-primary p-3" wire:key="je-line-{{ $index }}">
                    <div class="grid grid-cols-1 gap-2 sm:grid-cols-12 sm:items-end">
                        <div class="sm:col-span-4">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_account_label') }}</span>
                                @if ($persisted)
                                    <span class="rounded border border-border-primary bg-sand/30 px-2 py-1.5 text-sm text-charcoal">{{ $line['account_label'] }}</span>
                                @else
                                    <input type="text" wire:model.live.debounce.300ms="accountQuery.{{ $index }}"
                                           placeholder="{{ __('opes.ledger_screen.je_line_account_search_placeholder') }}"
                                           value="{{ $line['account_label'] !== '' ? $line['account_label'] : '' }}"
                                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                                    @if (($accountQuery[$index] ?? '') !== '')
                                        <ul class="max-h-40 overflow-y-auto rounded border border-border-primary bg-white text-sm shadow-sm">
                                            @forelse ($this->accountMatches($index) as $match)
                                                <li>
                                                    <button type="button" wire:click="pickAccount({{ $index }}, {{ $match->id }})"
                                                            class="block w-full px-2 py-1 text-left hover:bg-sand/40">
                                                        {{ $match->code }} — {{ $match->display_alias ?? $match->name }}
                                                    </button>
                                                </li>
                                            @empty
                                                <li class="px-2 py-1 text-charcoal/50">{{ __('opes.ledger_screen.je_line_account_no_match') }}</li>
                                            @endforelse
                                        </ul>
                                    @endif
                                @endif
                            </label>
                        </div>

                        <div class="sm:col-span-3">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_label') }}</span>
                                <input type="text" wire:model="lines.{{ $index }}.label" @disabled($persisted)
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
                            </label>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_debit') }}</span>
                                <input type="number" min="0" step="1" wire:model.live.debounce.300ms="lines.{{ $index }}.debit" @disabled($persisted)
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-right text-sm text-charcoal disabled:bg-sand/40"/>
                            </label>
                        </div>

                        <div class="sm:col-span-2">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_credit') }}</span>
                                <input type="number" min="0" step="1" wire:model.live.debounce.300ms="lines.{{ $index }}.credit" @disabled($persisted)
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-right text-sm text-charcoal disabled:bg-sand/40"/>
                            </label>
                        </div>

                        <div class="sm:col-span-1">
                            @if (! $persisted)
                                <button type="button" wire:click="removeLine({{ $index }})"
                                        class="w-full rounded border border-border-primary px-2 py-1.5 text-xs font-medium text-heritage-red hover:border-heritage-red/50">
                                    {{ __('opes.ledger_screen.je_line_remove') }}
                                </button>
                            @else
                                <span class="block text-center text-xs text-charcoal/50">{{ __('opes.ledger_screen.je_line_saved') }}</span>
                            @endif
                        </div>
                    </div>

                    {{-- Partner fields: shown only when the picked account is
                         collective (its real is_collective flag), never a
                         hardcoded account-code list - task brief. --}}
                    @if ($isCollective)
                        <div class="mt-2 grid grid-cols-1 gap-2 sm:grid-cols-3">
                            <label class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_partner_type') }}</span>
                                <select wire:model="lines.{{ $index }}.partner_type" @disabled($persisted)
                                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40">
                                    <option value="">{{ __('opes.ledger_screen.choose') }}</option>
                                    @foreach ($allowedPartnerTypes as $partnerType)
                                        <option value="{{ $partnerType }}">{{ __('opes.ledger_screen.partner_type_'.$partnerType) }}</option>
                                    @endforeach
                                </select>
                            </label>
                            <label class="flex flex-col gap-1 sm:col-span-2">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_line_partner_id') }}</span>
                                <input type="number" min="1" wire:model="lines.{{ $index }}.partner_id" @disabled($persisted)
                                       placeholder="{{ __('opes.ledger_screen.je_line_partner_id_placeholder') }}"
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal disabled:bg-sand/40"/>
                            </label>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>

        <button type="button" wire:click="addLine"
                class="mt-3 rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.ledger_screen.je_add_line') }}
        </button>
    </section>

    {{-- Live balance readout - visible BEFORE Post is attempted (task
         brief: nobody should have to submit-and-fail to discover an
         imbalance). Recomputed in render() on every debounced keystroke on
         a debit/credit input. --}}
    <section class="rounded border-2 {{ $isBalanced ? 'border-primary/40 bg-primary/5' : 'border-heritage-red/40 bg-heritage-red/5' }} p-4">
        <div class="flex flex-wrap items-center justify-between gap-3">
            <div class="flex gap-6 text-sm">
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-charcoal/60">{{ __('opes.ledger_screen.je_sum_debit') }}</div>
                    <div class="font-mono text-lg font-semibold text-charcoal">{{ Money::of($totalDebit)->format() }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-charcoal/60">{{ __('opes.ledger_screen.je_sum_credit') }}</div>
                    <div class="font-mono text-lg font-semibold text-charcoal">{{ Money::of($totalCredit)->format() }}</div>
                </div>
                <div>
                    <div class="text-xs font-medium uppercase tracking-wide text-charcoal/60">{{ __('opes.ledger_screen.je_sum_difference') }}</div>
                    <div class="font-mono text-lg font-semibold {{ $isBalanced ? 'text-primary' : 'text-heritage-red' }}">
                        {{ Money::of($totalDebit - $totalCredit)->format() }}
                    </div>
                </div>
            </div>
            <x-status-pill :status="$isBalanced ? 'ok' : 'red'"
                            :label="$isBalanced ? __('opes.ledger_screen.je_balanced') : __('opes.ledger_screen.je_not_balanced')"/>
        </div>
    </section>

    <div class="flex flex-wrap items-center gap-2">
        <button type="button" wire:click="saveDraft" wire:loading.attr="disabled"
                class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.ledger_screen.je_save_draft') }}
        </button>

        <button type="button" wire:click="post" wire:loading.attr="disabled" @disabled(! $isBalanced)
                class="rounded border border-primary bg-primary px-4 py-2 text-sm font-medium text-white hover:bg-primary/90 disabled:cursor-not-allowed disabled:opacity-50">
            {{ __('opes.ledger_screen.je_post') }}
        </button>

        <a href="{{ route('ledger.journal-entries.index') }}"
           class="rounded px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
            {{ __('opes.ledger_screen.cancel') }}
        </a>
    </div>
</div>
