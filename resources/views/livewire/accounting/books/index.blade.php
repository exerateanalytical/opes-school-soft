<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.books_screen.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-charcoal/70">{{ __('opes.books_screen.intro') }}</p>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="flex flex-wrap items-end gap-3">
            <label class="text-sm">
                <span class="block text-charcoal/70">{{ __('opes.books_screen.fiscal_year') }}</span>
                <select wire:model="fiscalYearId" class="mt-1 rounded border border-border-primary p-2">
                    @foreach ($fiscalYears as $year)
                        <option value="{{ $year->id }}">{{ $year->code }}</option>
                    @endforeach
                </select>
            </label>

            <label class="text-sm">
                <span class="block text-charcoal/70">{{ __('opes.books_screen.book_type') }}</span>
                <select wire:model="bookType" class="mt-1 rounded border border-border-primary p-2">
                    @foreach ($bookTypes as $type)
                        <option value="{{ $type->value }}">{{ $type->label() }}</option>
                    @endforeach
                </select>
            </label>

            <button type="button" wire:click="generate" wire:loading.attr="disabled"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                {{ __('opes.books_screen.generate') }}
            </button>
        </div>
    </section>

    <section class="overflow-x-auto rounded-lg border border-border-primary bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.book') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.period') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.generated') }}</th>
                <th class="p-2 text-right font-semibold">{{ __('opes.books_screen.lines') }}</th>
                <th class="p-2 text-right font-semibold">{{ __('opes.books_screen.debit') }}</th>
                <th class="p-2 text-right font-semibold">{{ __('opes.books_screen.credit') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.hash') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.supersedes') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($books as $book)
                <tr class="border-t border-border-primary">
                    <td class="p-2">{{ $book->book_type->label() }}</td>
                    <td class="p-2 whitespace-nowrap">{{ $book->period_start?->format('Y-m-d') }} → {{ $book->period_end?->format('Y-m-d') }}</td>
                    <td class="p-2 whitespace-nowrap">{{ $book->generated_at?->format('Y-m-d H:i') }}</td>
                    <td class="p-2 text-right font-mono">{{ number_format($book->line_count) }}</td>
                    <td class="p-2 text-right font-mono">{{ \App\Support\Money\Money::of((int) $book->total_debit)->format(false) }}</td>
                    <td class="p-2 text-right font-mono">{{ \App\Support\Money\Money::of((int) $book->total_credit)->format(false) }}</td>
                    <td class="p-2 font-mono text-xs" title="{{ $book->sha256 }}">{{ substr($book->sha256, 0, $hashPrefix) }}…</td>
                    <td class="p-2">{{ $book->supersedes_book_id ? '#'.$book->supersedes_book_id : '—' }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="p-4 text-center text-charcoal/60">{{ __('opes.books_screen.empty') }}</td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </section>

    {{-- A statutory register must never look shorter than it is. --}}
    @if ($bookTotal > $listLimit)
        <p class="text-xs text-charcoal/60">
            {{ __('opes.books_screen.showing', ['shown' => $listLimit, 'total' => $bookTotal]) }}
        </p>
    @endif
</div>
