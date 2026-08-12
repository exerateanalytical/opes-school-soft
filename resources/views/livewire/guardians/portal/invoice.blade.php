<div class="min-w-0 space-y-5">
    <div class="min-w-0">
        <a href="{{ route('portal.children.fees', $studentId) }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ $childName }} &middot; {{ __('opes.guardian_portal.tab_fees') }}
        </a>

        <h1 class="mt-1 text-2xl font-bold text-charcoal">
            {{ $invoice->invoice_no ?? '#'.$invoice->id }}
        </h1>
    </div>

    <section aria-labelledby="portal-invoice-head" class="rounded-2xl border border-border-primary bg-white p-4 shadow-[0_2px_10px_rgba(0,45,23,0.06)] sm:p-5">
        <h2 id="portal-invoice-head" class="sr-only">{{ __('opes.guardian_portal.fees_tab_invoices') }}</h2>

        <dl class="divide-y divide-border-secondary text-sm">
            @foreach ([
                [__('opes.guardian_portal.invoice_number'), $invoice->invoice_no ?? '—'],
                [__('opes.guardian_portal.invoice_issued'), $invoice->issue_date],
                [__('opes.guardian_portal.fees_due'), $invoice->due_date],
            ] as [$label, $value])
                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-charcoal/60">{{ trim($label) }}</dt>
                    <dd class="font-medium text-charcoal">{{ $value }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section aria-labelledby="portal-invoice-lines" class="rounded-2xl border border-border-primary bg-white shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
        <h2 id="portal-invoice-lines" class="border-b border-border-secondary px-4 py-3 text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.invoice_lines') }}
        </h2>

        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-secondary text-left text-xs uppercase tracking-wide text-charcoal/60">
                        <th scope="col" class="px-4 py-2 font-medium">{{ __('opes.guardian_portal.results_subject') }}</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('opes.guardian_portal.fees_amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-secondary">
                    @foreach ($invoice->lines as $line)
                        <tr wire:key="inv-line-{{ $line['line_no'] }}">
                            <td class="px-4 py-2 text-charcoal">
                                {{ app()->getLocale() === 'fr' && $line['description_fr'] ? $line['description_fr'] : $line['description'] }}
                            </td>
                            <td class="px-4 py-2 text-right tabular-nums text-charcoal">
                                {{ number_format($line['amount'] + $line['tax_amount'], 0, ',', ' ') }}
                                <span class="text-xs text-charcoal/60">{{ $invoice->currency }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
                <tfoot>
                    <tr class="border-t border-border-primary">
                        <td class="px-4 py-3 text-sm font-semibold text-charcoal">{{ __('opes.guardian_portal.invoice_total') }}</td>
                        <td class="px-4 py-3 text-right text-sm font-bold tabular-nums text-charcoal">
                            {{ number_format($invoice->total, 0, ',', ' ') }}
                            <span class="text-xs font-normal text-charcoal/60">{{ $invoice->currency }}</span>
                        </td>
                    </tr>
                </tfoot>
            </table>
        </div>
    </section>
</div>
