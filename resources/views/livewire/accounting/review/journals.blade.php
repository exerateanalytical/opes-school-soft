<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.accounting.review.journals_heading') }}</h1>
        <p class="mt-1 text-sm text-charcoal/60">{{ __('opes.accounting.review.journals_explainer') }}</p>
    </header>

    <nav class="flex flex-wrap gap-2 text-sm">
        @foreach (App\Modules\Accounting\Actions\Review\JournalExceptions::FILTERS as $key)
            <button
                type="button"
                wire:click="$set('filter', '{{ $key }}')"
                class="rounded-full border px-3 py-1 {{ $filter === $key ? 'border-charcoal bg-charcoal text-white' : 'border-charcoal/20 text-charcoal/70 hover:border-charcoal/40' }}"
            >
                {{ __('opes.accounting.review.journal_'.$key) }}
                {{-- ?? 0 is safe here and only here: the tabs iterate FILTERS,
                     and counts() aggregates over the SAME list, so a missing key
                     is a genuinely empty category, not an unknown figure. --}}
                <span class="ml-1 tabular-nums">{{ $counts[$key] ?? 0 }}</span>
            </button>
        @endforeach
    </nav>

    <div class="overflow-x-auto">
        <table class="w-full text-sm">
            <thead>
                <tr class="text-left text-charcoal/60">
                    <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.date') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.piece') }}</th>
                    <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.label') }}</th>
                    <th class="py-2 pr-4 text-right font-medium">{{ __('opes.accounting.review.amount') }}</th>
                    <th class="py-2 font-medium">{{ __('opes.accounting.review.source') }}</th>
                </tr>
            </thead>
            <tbody>
                @forelse ($entries as $entry)
                    <tr class="border-t border-charcoal/10">
                        <td class="py-2 pr-4 whitespace-nowrap">{{ $entry->date->toDateString() }}</td>
                        <td class="py-2 pr-4 font-mono text-xs">{{ $entry->piece_no ?? '—' }}</td>
                        <td class="py-2 pr-4">{{ $entry->label }}</td>
                        <td class="py-2 pr-4 text-right tabular-nums">{{ \App\Support\Money\Money::of((int) $entry->total_debit)->format(false) }}</td>
                        <td class="py-2">
                            <x-accounting.source-link :reference="$references[$entry->id]" />
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="py-4 text-charcoal/60">{{ __('opes.accounting.review.no_entries') }}</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $entries->links() }}
</div>
