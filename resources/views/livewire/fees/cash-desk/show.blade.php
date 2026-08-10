@php
    use App\Support\Money\Money;
@endphp

{{--
    Cash-desk close-out sheet (04-fees §11.7). The document the bursar signs:
    who, which box, from when to when, float in, everything collected,
    expected, counted, and the variance with its mandatory reason.

    Single root element - Livewire requires it and this project has broken on
    that more than once.
--}}
<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.fees_screen.breadcrumb_finance') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('fees.cashier') }}" class="hover:text-primary">{{ __('opes.fees_screen.breadcrumb_cashier') }}</a>
            </li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $session['session_no'] }}</span>
            </li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="min-w-0 text-xl font-semibold text-charcoal">Cash desk close-out</h1>
            <p class="mt-1 font-mono text-sm text-charcoal/70">{{ $session['session_no'] }}</p>
        </div>
        <div class="flex flex-wrap items-center gap-2">
            <span class="rounded-full border px-3 py-1 text-xs font-medium
                {{ $session['status'] === 'open' ? 'border-primary/40 bg-primary/10 text-primary' : 'border-border-primary bg-sand/40 text-charcoal/70' }}">
                {{ ucfirst($session['status']) }}
            </span>
            <button type="button" wire:click="exportPdf"
                    class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Export PDF
            </button>
        </div>
    </div>

    <section class="rounded border border-border-primary bg-white p-4">
        <dl class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
            <div>
                <dt class="text-xs uppercase tracking-wide text-charcoal/60">Cash box</dt>
                <dd class="mt-1 text-sm text-charcoal">{{ $session['treasury_label'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-charcoal/60">Business date</dt>
                <dd class="mt-1 font-mono text-sm text-charcoal">{{ $session['business_date'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-charcoal/60">Opened by</dt>
                <dd class="mt-1 text-sm text-charcoal">{{ $session['opened_by_name'] }}</dd>
                <dd class="font-mono text-xs text-charcoal/60">{{ $session['opened_at'] }}</dd>
            </div>
            <div>
                <dt class="text-xs uppercase tracking-wide text-charcoal/60">Closed by</dt>
                <dd class="mt-1 text-sm text-charcoal">{{ $session['closed_by_name'] ?? '—' }}</dd>
                <dd class="font-mono text-xs text-charcoal/60">{{ $session['closed_at'] ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    <section class="rounded border border-border-primary bg-white">
        <h2 class="border-b border-border-primary px-4 py-3 text-sm font-semibold text-primary">
            Collections ({{ $session['collections'] }})
        </h2>

        @if ($collections === [])
            <p class="px-4 py-6 text-sm text-charcoal/60">This session took no collections.</p>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead class="bg-sand/40 text-charcoal/70">
                        <tr>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">Receipt</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">Time</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">Student</th>
                            <th scope="col" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">Payer</th>
                            <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">Amount</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($collections as $line)
                            <tr wire:key="cds-line-{{ $loop->index }}">
                                <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['receipt_no'] }}</td>
                                <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['time'] }}</td>
                                <td class="px-4 py-2 text-charcoal">{{ $line['student'] }}</td>
                                <td class="px-4 py-2 text-charcoal/70">{{ $line['payer'] }}</td>
                                <td class="px-4 py-2 text-right font-mono text-charcoal">{{ Money::of($line['amount'])->format(false) }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    <section class="rounded border border-border-primary bg-white p-4">
        <h2 class="text-sm font-semibold text-primary">Close-out</h2>
        <dl class="mt-3 space-y-2 text-sm">
            <div class="flex justify-between gap-2">
                <dt class="text-charcoal/60">Opening float</dt>
                <dd class="font-mono text-charcoal">{{ Money::of($session['opening_float'])->format() }}</dd>
            </div>
            <div class="flex justify-between gap-2">
                <dt class="text-charcoal/60">Collections</dt>
                <dd class="font-mono text-charcoal">{{ Money::of($session['collected'])->format() }}</dd>
            </div>
            <div class="flex justify-between gap-2 border-t border-border-primary pt-2">
                <dt class="font-medium text-charcoal">Expected in till</dt>
                <dd class="font-mono font-semibold text-charcoal">{{ Money::of($session['expected'])->format() }}</dd>
            </div>

            @if ($session['status'] !== 'open')
                <div class="flex justify-between gap-2">
                    <dt class="text-charcoal/60">Counted</dt>
                    <dd class="font-mono text-charcoal">{{ Money::of((int) $session['counted_cash'])->format() }}</dd>
                </div>
                <div class="flex justify-between gap-2 border-t border-border-primary pt-2">
                    <dt class="font-medium text-charcoal">Variance</dt>
                    <dd class="font-mono font-bold {{ (int) $session['variance'] === 0 ? 'text-charcoal' : 'text-heritage-red' }}">
                        {{ Money::of((int) $session['variance'])->format() }}
                    </dd>
                </div>
            @endif
        </dl>

        @if ($session['status'] !== 'open' && (int) $session['variance'] !== 0)
            <div class="mt-3 rounded border border-heritage-red/40 bg-heritage-red/10 p-3">
                <p class="text-xs uppercase tracking-wide text-heritage-red">
                    {{ (int) $session['variance'] < 0 ? 'Shortage' : 'Overage' }} — declared reason
                </p>
                <p class="mt-1 text-sm text-charcoal">{{ $session['variance_reason'] }}</p>
                @if ($session['journal_entry_id'] !== null)
                    <p class="mt-2 text-xs text-charcoal/70">
                        Posted as journal entry
                        <span class="font-mono">{{ $session['piece_no'] ?? ('#'.$session['journal_entry_id']) }}</span>
                        via <span class="font-mono">cashdesk.closed_with_variance</span>.
                    </p>
                @endif
            </div>
        @endif
    </section>
</div>
