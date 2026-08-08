@php
    use App\Support\Money\Money;

    $balanced = $totalDebit === $totalCredit;
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.ledger_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>{{ __('opes.ledger_screen.breadcrumb_ledger') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span><span aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.ledger_screen.breadcrumb_trial_balance') }}</span></li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ __('opes.ledger_screen.tb_title') }}</h1>
    </div>

    <section aria-label="{{ __('opes.ui.filters') }}" class="rounded border border-sand bg-white p-3">
        <label for="tb-fiscal-year" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.tb_fiscal_year_label') }}</span>
            <select id="tb-fiscal-year" wire:model.live="fiscalYearId"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                @foreach ($fiscalYearOptions as $fiscalYear)
                    <option value="{{ $fiscalYear->id }}">{{ $fiscalYear->code }}</option>
                @endforeach
            </select>
        </label>
    </section>

    @if ($rows->isEmpty())
        <x-empty-state :message="__('opes.ledger_screen.tb_empty')"/>
    @else
        <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
            <table class="w-full min-w-[36rem] border-collapse text-sm">
                <thead class="border-b border-sand bg-chrome text-left text-white">
                    <tr>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.tb_column_code') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.tb_column_account') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.tb_column_debit') }}</th>
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.tb_column_credit') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @foreach ($rows as $row)
                        <tr>
                            <td class="px-4 py-2.5 font-mono text-charcoal">{{ $row->code }}</td>
                            <td class="px-4 py-2.5 text-charcoal">{{ $row->name }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_debit === 0 ? '' : Money::of($row->total_debit)->format(false) }}</td>
                            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ $row->total_credit === 0 ? '' : Money::of($row->total_credit)->format(false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t-2 border-charcoal/20 bg-sand/40 font-semibold">
                        <td class="px-4 py-2.5 text-charcoal" colspan="2">{{ __('opes.ledger_screen.tb_grand_total') }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($totalDebit)->format(false) }}</td>
                        <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($totalCredit)->format(false) }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>

        {{-- L2/§9.3 guarantee the two grand totals are always equal for a
             ledger with no integrity fault. If they are not, that is a real
             upstream bug and this banner says so plainly - it is never
             hidden behind a rounding fudge (task brief). --}}
        @if (! $balanced)
            <div class="rounded border border-heritage-red/40 bg-heritage-red/10 px-4 py-3 text-sm text-heritage-red" role="alert">
                {{ __('opes.ledger_screen.tb_out_of_balance', [
                    'debit' => Money::of($totalDebit)->format(),
                    'credit' => Money::of($totalCredit)->format(),
                ]) }}
            </div>
        @endif
    @endif
</div>
