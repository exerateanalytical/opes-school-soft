{{-- The "What's open right now" panel, docs/specs/08-operations.md §6.4.
     Rendered on the dashboard for anyone holding fee.view or ledger.view
     (Bursar, Accountant, Principal, Administrator - the spec's list); an
     empty root for everyone else, because the dashboard is shared ground
     and this panel is simply not their business. --}}
<div>
    @if ($data !== null)
        <section aria-labelledby="opes-whats-open" class="rounded border border-border-primary bg-white px-4 py-3">
            <h2 id="opes-whats-open" class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.whats_open.title') }}
            </h2>

            <dl class="mt-3 grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-3">
                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.academic_year') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                        @if ($data['year'] !== null)
                            {{ $data['year']->code }}
                            <span class="block text-xs font-normal text-charcoal/60">
                                {{ $data['year']->starts_on }} &rarr; {{ $data['year']->ends_on }}
                            </span>
                        @else
                            {{ __('opes.whats_open.no_academic_year') }}
                        @endif
                    </dd>
                </div>

                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.exercice') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                        @if ($data['exercice'] !== null)
                            {{ $data['exercice']->code }}
                            <span class="block text-xs font-normal text-charcoal/60">
                                {{ $data['exercice']->starts_on }} &rarr; {{ $data['exercice']->ends_on }}
                            </span>
                        @else
                            {{ __('opes.whats_open.no_exercice') }}
                        @endif
                    </dd>
                </div>

                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.current_period') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                        @if ($data['period'] !== null)
                            {{ \Illuminate\Support\Carbon::parse((string) $data['period']->period_month)->isoFormat('MMMM YYYY') }}
                            <span class="mt-1 block">
                                <x-status-pill :status="$data['period']->status === 'open' ? 'ok' : ($data['period']->status === 'soft_locked' ? 'amber' : 'red')"
                                               :label="__('opes.whats_open.period_'.$data['period']->status)"/>
                            </span>
                        @else
                            {{ __('opes.whats_open.no_period') }}
                        @endif
                    </dd>
                </div>

                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.locked_months') }}</dt>
                    <dd class="mt-0.5 text-sm text-charcoal">
                        @if ($data['locked'] === [])
                            <span class="font-semibold">{{ __('opes.whats_open.no_locked_months') }}</span>
                        @else
                            <ul class="flex flex-wrap gap-1.5">
                                @foreach ($data['locked'] as $month)
                                    <li class="rounded-full border px-2 py-0.5 text-xs font-medium
                                               {{ $month['status'] === 'hard_locked'
                                                    ? 'border-heritage-red/40 bg-heritage-red/10 text-heritage-red'
                                                    : 'border-heritage-yellow/60 bg-heritage-yellow/10 text-charcoal' }}">
                                        {{ $month['month'] }} &middot; {{ __('opes.whats_open.period_'.$month['status']) }}
                                    </li>
                                @endforeach
                            </ul>
                        @endif
                    </dd>
                </div>

                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.next_quarter_closure') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                        {{ $data['nextClosure'] ?? __('opes.whats_open.no_quarter_closure') }}
                    </dd>
                </div>

                <div class="rounded border border-border-primary bg-sand/20 p-3">
                    <dt class="text-xs text-charcoal/60">{{ __('opes.whats_open.marks_entry') }}</dt>
                    <dd class="mt-0.5 text-sm font-semibold text-charcoal">
                        @if ($data['marksOpen'] > 0)
                            {{ __('opes.whats_open.marks_open', ['count' => $data['marksOpen']]) }}
                        @else
                            {{ __('opes.whats_open.marks_closed') }}
                        @endif
                    </dd>
                </div>
            </dl>
        </section>
    @endif
</div>
