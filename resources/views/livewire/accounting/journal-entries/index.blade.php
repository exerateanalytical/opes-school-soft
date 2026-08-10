@php
    use App\Support\Money\Money;

    $statusTone = [
        \App\Modules\Accounting\Models\JournalEntry::STATUS_DRAFT => 'amber',
        \App\Modules\Accounting\Models\JournalEntry::STATUS_POSTED => 'ok',
        \App\Modules\Accounting\Models\JournalEntry::STATUS_REVERSED => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <x-list-screen
    :title="__('opes.ledger_screen.je_title')"
    :breadcrumb="[__('opes.ledger_screen.breadcrumb_dashboard'), __('opes.ledger_screen.breadcrumb_ledger'), __('opes.ledger_screen.breadcrumb_je')]"
    :paginator="$entries"
    :empty-message="__('opes.ledger_screen.je_empty')"
>
    <x-slot:actions>
        @can('ledger.post')
            <a href="{{ route('ledger.journal-entries.create') }}"
               class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.ledger_screen.je_new_entry') }}
            </a>
        @endcan
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.ledger_screen.kpi_entries_this_year')" :value="$entriesThisFiscalYear" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path stroke-linecap="round" d="M3 9h18M8 4v3M16 4v3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.ledger_screen.kpi_unposted_drafts')" :value="$unpostedDrafts" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v5l3 3M12 3a9 9 0 100 18 9 9 0 000-18z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="je-filter-journal" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_journal_label') }}</span>
            <select id="je-filter-journal" wire:model.live="journalId"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($journalOptions as $journal)
                    <option value="{{ $journal->id }}">{{ $journal->code }} — {{ $journal->name }}</option>
                @endforeach
            </select>
        </label>

        <label for="je-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_status_label') }}</span>
            <select id="je-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="draft">{{ __('opes.ledger_screen.je_status_draft') }}</option>
                <option value="posted">{{ __('opes.ledger_screen.je_status_posted') }}</option>
                <option value="reversed">{{ __('opes.ledger_screen.je_status_reversed') }}</option>
            </select>
        </label>

        <label for="je-filter-date-from" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_date_from_label') }}</span>
            <input id="je-filter-date-from" type="date" wire:model.live="dateFrom"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="je-filter-date-to" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_date_to_label') }}</span>
            <input id="je-filter-date-to" type="date" wire:model.live="dateTo"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_piece') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_date') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_journal') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_label') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_debit') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_credit') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.je_column_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.column_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($entries as $entry)
        <tr wire:key="je-row-{{ $entry->id }}">
            <td class="px-4 py-2.5 font-mono text-charcoal">{{ $entry->piece_no ?? __('opes.ledger_screen.je_piece_draft_placeholder') }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $entry->date->format('d/m/Y') }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $journalOptions->get($entry->journal_id)?->code ?? '—' }}</td>
            <td class="px-4 py-2.5 text-charcoal">{{ $entry->label }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($entry->total_debit)->format(false) }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($entry->total_credit)->format(false) }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$statusTone[$entry->status] ?? 'ok'" :label="__('opes.ledger_screen.je_status_'.$entry->status)"/>
            </td>
            <td class="px-4 py-2.5 text-right">
                @if ($entry->status === \App\Modules\Accounting\Models\JournalEntry::STATUS_DRAFT)
                    <a href="{{ route('ledger.journal-entries.create', ['entry' => $entry->id]) }}"
                       class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.ledger_screen.je_continue_draft') }}
                    </a>
                @elseif ($canReverse && $entry->status === \App\Modules\Accounting\Models\JournalEntry::STATUS_POSTED)
                    <button type="button" wire:click="startReverse({{ $entry->id }})"
                            class="rounded border border-heritage-red/40 px-2 py-1 text-xs font-medium text-heritage-red hover:border-heritage-red/70">
                        {{ __('opes.ledger_screen.je_reverse') }}
                    </button>
                @endif
            </td>
        </tr>
        @if ($reversingEntryId === $entry->id)
            <tr wire:key="je-reverse-row-{{ $entry->id }}">
                <td colspan="8" class="bg-heritage-red/5 px-4 py-3">
                    <div class="flex flex-wrap items-end gap-3">
                        <label for="je-reverse-reason-{{ $entry->id }}" class="flex min-w-[20rem] flex-1 flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.je_reverse_reason_label') }}</span>
                            <input id="je-reverse-reason-{{ $entry->id }}" type="text" wire:model="reverseReason"
                                   placeholder="{{ __('opes.ledger_screen.je_reverse_reason_placeholder') }}"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('reverseReason') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                        </label>
                        <button type="button" wire:click="reverseEntry"
                                wire:confirm="{{ __('opes.ledger_screen.je_reverse_confirm') }}"
                                class="rounded bg-heritage-red px-3 py-1.5 text-xs font-semibold text-white hover:bg-heritage-red/90">
                            {{ __('opes.ledger_screen.je_reverse_submit') }}
                        </button>
                        <button type="button" wire:click="cancelReverse"
                                class="rounded border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal/70 hover:text-charcoal">
                            {{ __('opes.ledger_screen.cancel') }}
                        </button>
                    </div>
                </td>
            </tr>
        @endif
    @endforeach

    <x-slot:cards>
        @foreach ($entries as $entry)
            <article class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <div class="font-mono text-sm font-medium text-charcoal">{{ $entry->piece_no ?? __('opes.ledger_screen.je_piece_draft_placeholder') }}</div>
                        <div class="truncate text-sm text-charcoal/80">{{ $entry->label }}</div>
                    </div>
                    <x-status-pill :status="$statusTone[$entry->status] ?? 'ok'" :label="__('opes.ledger_screen.je_status_'.$entry->status)"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.ledger_screen.je_column_date') }}</dt>
                        <dd>{{ $entry->date->format('d/m/Y') }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.ledger_screen.je_column_debit') }}</dt>
                        <dd class="font-mono">{{ Money::of($entry->total_debit)->format(false) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.ledger_screen.je_column_credit') }}</dt>
                        <dd class="font-mono">{{ Money::of($entry->total_credit)->format(false) }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-slot:cards>
    </x-list-screen>
</div>
