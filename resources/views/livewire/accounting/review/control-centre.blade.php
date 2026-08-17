@php use App\Modules\Accounting\Domain\ControlStatus; @endphp

<div class="space-y-8">
    <header class="flex flex-wrap items-baseline justify-between gap-3">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.accounting.review.heading') }}</h1>
            <p class="mt-1 text-sm text-charcoal/60">{{ __('opes.accounting.review.subheading') }}</p>
        </div>

        {{-- Every balance states its axis and its as_of. The fiscal and academic
             answers differ by a full term; an unlabelled figure is a trap. --}}
        <div class="flex flex-wrap items-center gap-3 text-sm">
            <label class="flex items-center gap-2">
                <span class="text-charcoal/60">{{ __('opes.accounting.review.axis_label') }}</span>
                <select wire:model.live="axis" class="rounded border-charcoal/20 text-sm">
                    <option value="fiscal_year">{{ __('opes.accounting.review.axis_fiscal_year') }}</option>
                    <option value="academic_year">{{ __('opes.accounting.review.axis_academic_year') }}</option>
                </select>
            </label>
            <span class="text-charcoal/60">
                {{ __('opes.accounting.review.as_of') }}
                <span class="font-medium text-charcoal">{{ $asOf }}</span>
            </span>
        </div>
    </header>

    <section>
        <h2 class="mb-1 text-lg font-medium text-charcoal">{{ __('opes.accounting.review.controls_heading') }}</h2>
        <p class="mb-3 text-sm text-charcoal/60">{{ __('opes.accounting.review.controls_explainer') }}</p>

        @if ($checks->isEmpty())
            <p class="rounded border border-charcoal/10 bg-charcoal/[0.02] p-4 text-sm text-charcoal/60">
                {{ __('opes.accounting.review.no_controls') }}
            </p>
        @else
            @if ($brokenCount === 0)
                <p class="mb-3 text-sm font-medium text-emerald-700">
                    &check; {{ __('opes.accounting.review.all_reconciled', ['count' => $checks->count()]) }}
                </p>
            @else
                <p class="mb-3 text-sm font-medium text-red-700">
                    &#9888; {{ __('opes.accounting.review.some_broken', ['count' => $brokenCount]) }}
                </p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-left text-charcoal/60">
                            <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.control') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('opes.accounting.review.expected') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('opes.accounting.review.actual') }}</th>
                            <th class="py-2 pr-4 text-right font-medium">{{ __('opes.accounting.review.difference') }}</th>
                            <th class="py-2 font-medium">{{ __('opes.accounting.review.status') }}</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($checks as $check)
                            <tr class="border-t border-charcoal/10">
                                <td class="py-2 pr-4">{{ $check->label }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $check->expected === null ? '—' : \App\Support\Money\Money::of((int) $check->expected)->format(false) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums">{{ $check->actual === null ? '—' : \App\Support\Money\Money::of((int) $check->actual)->format(false) }}</td>
                                <td class="py-2 pr-4 text-right tabular-nums {{ $check->difference !== 0 ? 'font-semibold text-red-700' : '' }}">
                                    {{ \App\Support\Money\Money::of((int) $check->difference)->format(false) }}
                                </td>
                                <td class="py-2">
                                    @if ($check->status === ControlStatus::Reconciled)
                                        <span class="text-emerald-700">&check; {{ __('opes.accounting.review.reconciled') }}</span>
                                    @elseif ($check->status === ControlStatus::Difference)
                                        <span class="text-red-700">&#9888; {{ __('opes.accounting.review.out_of_balance') }}</span>
                                    @else
                                        <span class="text-charcoal/50" title="{{ $check->blockingGate }}">
                                            &mdash; {{ __('opes.accounting.review.not_configured') }}
                                        </span>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section>
        <h2 class="mb-1 text-lg font-medium text-charcoal">{{ __('opes.accounting.review.suspense_heading') }}</h2>
        <p class="mb-3 text-sm text-charcoal/60">{{ __('opes.accounting.review.suspense_explainer') }}</p>

        @forelse ($suspense as $row)
            <div class="flex justify-between border-t border-charcoal/10 py-2 text-sm">
                <span><span class="font-mono text-xs text-charcoal/60">{{ $row->code }}</span> {{ $row->name }}</span>
                <span class="tabular-nums font-medium text-amber-700">{{ \App\Support\Money\Money::of((int) $row->balance)->format(false) }}</span>
            </div>
        @empty
            <p class="text-sm text-emerald-700">&check; {{ __('opes.accounting.review.suspense_empty') }}</p>
        @endforelse
    </section>

    <section>
        <h2 class="mb-1 text-lg font-medium text-charcoal">{{ __('opes.accounting.review.gates_heading') }}</h2>
        <p class="mb-3 text-sm text-charcoal/60">{{ __('opes.accounting.review.gates_explainer') }}</p>

        <p class="mb-3 text-sm font-medium {{ $openGateCount === 0 ? 'text-emerald-700' : 'text-amber-700' }}">
            {{ __('opes.accounting.review.gates_open', ['open' => $openGateCount, 'total' => count($gates)]) }}
        </p>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="text-left text-charcoal/60">
                        <th class="w-10 py-2 pr-4 font-medium">#</th>
                        <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.gate_item') }}</th>
                        <th class="py-2 pr-4 font-medium">{{ __('opes.accounting.review.gate_blocks') }}</th>
                        <th class="py-2 font-medium">{{ __('opes.accounting.review.status') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($gates as $gate)
                        <tr class="border-t border-charcoal/10">
                            <td class="py-2 pr-4 text-charcoal/50">{{ $gate['number'] }}</td>
                            <td class="py-2 pr-4">{{ $gate['item'] }}</td>
                            <td class="py-2 pr-4 text-charcoal/60">{{ $gate['blocks'] }}</td>
                            <td class="py-2">
                                @if ($gate['configured'])
                                    <span class="text-emerald-700">&check; {{ __('opes.accounting.review.configured') }}</span>
                                @else
                                    <span class="text-amber-700">
                                        {{ __('opes.accounting.review.not_configured') }}
                                        @if ($gate['missing'] !== [])
                                            <span class="text-charcoal/50">({{ implode(', ', $gate['missing']) }})</span>
                                        @endif
                                    </span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>
</div>
