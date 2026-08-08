@php
    use App\Support\Money\Money;
@endphp

{{--
    Student fee statement - F4's StudentStatement rendered chronologically
    with a running balance, printable (mockup panel 4's "official receipt"
    vocabulary informs the print layout: school name header, student
    identity block, signature line). The @media print rules hide the app
    chrome and screen-only controls.
--}}
<div class="min-w-0 space-y-4 print:space-y-2">
    <style media="print">
        /* The shell's sidebar/topbar carry no print class hooks of their own,
           so the statement declares them out of the printed page here. */
        aside, nav, header, .statement-screen-only { display: none !important; }
        main { padding: 0 !important; }
        body { background: #fff !important; }
    </style>

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="statement-screen-only min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.fees_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>{{ __('opes.fees_screen.breadcrumb_finance') }}</li>
            <li class="flex items-center gap-1"><span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.fees_screen.breadcrumb_statement') }}</span>
            </li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.fees_screen.statement_title') }}</h1>
            <p class="mt-1 text-sm text-charcoal/70">
                <span class="font-medium text-charcoal">{{ $student['name'] }}</span>
                <span aria-hidden="true"> · </span>
                <span class="font-mono">{{ $student['matricule'] }}</span>
                @if ($student['class'] !== '')
                    <span aria-hidden="true"> · </span>{{ $student['class'] }}
                @endif
            </p>
        </div>

        <div class="statement-screen-only flex flex-wrap items-center gap-2">
            <a href="{{ route('fees.cashier') }}"
               class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.fees_screen.back_to_cashier') }}
            </a>
            <button type="button" onclick="window.print()"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ __('opes.fees_screen.print') }}
            </button>
        </div>
    </div>

    @if ($lines === [])
        <x-empty-state :message="__('opes.fees_screen.statement_empty')"/>
    @else
        <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white print:overflow-visible print:rounded-none print:border-0">
            <table class="w-full min-w-[36rem] border-collapse text-sm">
                <thead class="border-b border-sand bg-sand/40 text-left">
                    <tr>
                        <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
                        <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_description') }}</th>
                        <th scope="col" class="px-4 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_reference') }}</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_debit') }}</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_credit') }}</th>
                        <th scope="col" class="px-4 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_running_balance') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    @foreach ($lines as $line)
                        <tr wire:key="statement-line-{{ $loop->index }}">
                            <td class="whitespace-nowrap px-4 py-2 text-charcoal/70">{{ $line['date'] }}</td>
                            <td class="px-4 py-2 text-charcoal">{{ $line['description'] }}</td>
                            <td class="px-4 py-2 font-mono text-xs text-charcoal/70">{{ $line['reference'] }}</td>
                            <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $line['debit'] > 0 ? Money::of($line['debit'])->format(false) : '' }}</td>
                            <td class="px-4 py-2 text-right font-mono text-charcoal">{{ $line['credit'] > 0 ? Money::of($line['credit'])->format(false) : '' }}</td>
                            <td class="px-4 py-2 text-right font-mono {{ $line['balance'] > 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ Money::of($line['balance'])->format(false) }}</td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot class="border-t border-sand bg-sand/30">
                    <tr>
                        <th scope="row" colspan="5" class="px-4 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.closing_balance') }}</th>
                        <td class="px-4 py-2 text-right font-mono font-bold {{ $closingBalance > 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ Money::of($closingBalance)->format() }}</td>
                    </tr>
                </tfoot>
            </table>
        </div>
    @endif
</div>
