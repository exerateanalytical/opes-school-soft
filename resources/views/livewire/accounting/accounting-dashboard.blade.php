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

    {{-- Needs attention today: a real task list, not another count. --}}
    <section aria-labelledby="acct-dash-attention">
        <h2 id="acct-dash-attention" class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.accounting.dashboard.attention_heading') }}
        </h2>

        @if ($closingYear === null && $draftEntries->isEmpty())
            <p class="rounded-xl border border-border-primary bg-white px-4 py-3 text-sm text-charcoal/60">
                {{ __('opes.accounting.dashboard.nothing_pending') }}
            </p>
        @else
            <ul class="space-y-2">
                @if ($closingYear !== null)
                    <li class="rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <a href="{{ route('accounting.year-end') }}" class="text-sm font-semibold text-charcoal hover:text-primary">
                            {{ __('opes.accounting.dashboard.fiscal_year_closing', ['code' => $closingYear->code]) }}
                        </a>
                    </li>
                @endif

                @foreach ($draftEntries as $entry)
                    <li class="rounded-xl border border-border-primary bg-white px-4 py-3 shadow-sm">
                        <a href="{{ route('ledger.journal-entries.index') }}" class="text-sm font-semibold text-charcoal hover:text-primary">
                            {{ __('opes.accounting.dashboard.draft_entry', ['label' => $entry->label]) }}
                        </a>
                    </li>
                @endforeach
            </ul>
        @endif
    </section>

    {{-- Money: real data, aged-receivables source (per-student, bucketed) -
         NOT the generic dashboard's single aggregate figure. --}}
    <section aria-labelledby="acct-dash-money" class="space-y-4">
        <h2 id="acct-dash-money" class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            {{ __('opes.accounting.dashboard.money_heading') }}
        </h2>

        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.aged_heading') }}
            </p>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-3">
                @foreach ($buckets as $key => $amount)
                    <div>
                        <p class="text-xs text-charcoal/55">{{ __('opes.accounting.dashboard.bucket_'.$key) }}</p>
                        <p class="text-sm font-bold tabular-nums {{ $amount > 0 ? 'text-charcoal' : 'text-charcoal/40' }}">
                            {{ number_format($amount) }}
                        </p>
                    </div>
                @endforeach
            </div>
        </div>

        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.top_debtors_heading') }}
            </p>
            @forelse ($topDebtors as $row)
                @php $student = $students[$row->student_id] ?? null; @endphp
                <div class="flex justify-between border-t border-charcoal/10 py-2 text-sm first:border-t-0">
                    <span>{{ $student !== null ? $student->first_name.' '.$student->last_name : __('opes.accounting.dashboard.unknown_student') }}</span>
                    <span class="tabular-nums font-medium">{{ number_format($row->net) }}</span>
                </div>
            @empty
                <p class="text-sm text-portal-success">{{ __('opes.accounting.dashboard.no_debtors') }}</p>
            @endforelse
        </div>

        <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
            <p class="mb-3 text-xs font-semibold uppercase tracking-wide text-charcoal/55">
                {{ __('opes.accounting.dashboard.trend_heading') }}
            </p>
            <p class="mb-2 text-xs text-charcoal/50">
                {{ __('opes.accounting.dashboard.trend_caveat') }}
            </p>
            <x-accounting.trend-chart
                :series="$trendSeries"
                :geometry="$trendGeometry"
                :end-label="now()->format('F Y')"/>
        </div>
    </section>
</div>
