<div class="space-y-8">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.accounting.dashboard.heading') }}</h1>

    {{-- Book health: three tiles, meant to read green almost always. --}}
    <section aria-labelledby="acct-dash-health" class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <h2 id="acct-dash-health" class="sr-only">{{ __('opes.accounting.dashboard.health_heading') }}</h2>

        <a href="{{ route('accounting.review') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.books_balanced') }}
            </p>
            @if ($brokenCount === 0)
                <p class="mt-2 text-lg font-bold text-portal-success">&check; {{ __('opes.accounting.dashboard.balanced') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-heritage-red">{{ __('opes.accounting.dashboard.out_of_balance_count', ['count' => $brokenCount]) }}</p>
            @endif
        </a>

        <a href="{{ route('ledger.journal-entries.index') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.unposted_entries') }}
            </p>
            @if ($draftCount === 0)
                <p class="mt-2 text-lg font-bold text-portal-success">{{ __('opes.accounting.dashboard.all_caught_up') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-charcoal">{{ $draftCount }}</p>
            @endif
        </a>

        <a href="{{ route('accounting.review') }}"
           class="rounded-xl border border-border-primary bg-white px-4 py-3.5 shadow-sm">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.uncategorised') }}
            </p>
            @if ($suspense->isEmpty())
                <p class="mt-2 text-lg font-bold text-portal-success">{{ __('opes.accounting.dashboard.none') }}</p>
            @else
                <p class="mt-2 text-lg font-bold text-heritage-red">{{ number_format((int) $suspense->sum('balance')) }}</p>
            @endif
        </a>
    </section>
</div>
