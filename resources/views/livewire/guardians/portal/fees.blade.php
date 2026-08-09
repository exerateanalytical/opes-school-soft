@php
    use App\Support\Money\Money;
@endphp
<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', ['studentId' => $studentId, 'childName' => $childName, 'active' => 'fees'])

    @if (! $hasEnrollment)
        <x-empty-state :message="__('opes.guardian_portal.fees_no_enrollment')"/>
    @else
        <div class="flex flex-wrap gap-1 border-b border-sand">
            @foreach (['statement' => __('opes.guardian_portal.fees_tab_statement'), 'invoices' => __('opes.guardian_portal.fees_tab_invoices'), 'receipts' => __('opes.guardian_portal.fees_tab_receipts')] as $tab => $label)
                @if ($tab === 'receipts' || $canWide)
                    <button type="button" wire:click="setTab('{{ $tab }}')"
                            @if ($activeTab === $tab) aria-current="page" @endif
                            class="rounded-t border-b-2 px-3 py-2 text-sm font-medium {{ $activeTab === $tab
                                ? 'border-primary text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ $label }}
                    </button>
                @endif
            @endforeach
        </div>

        @if ($activeTab === 'statement' && $canWide)
            @if ($statement === [])
                <x-empty-state :message="__('opes.guardian_portal.fees_statement_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[32rem] border-collapse text-sm">
                        <thead class="border-b border-sand bg-sand/40 text-left">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_description') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_debit') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_credit') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_running_balance') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($statement as $line)
                                <tr wire:key="portal-statement-{{ $loop->index }}">
                                    <td class="whitespace-nowrap px-3 py-2 text-charcoal/70">{{ $line['date'] }}</td>
                                    <td class="px-3 py-2 text-charcoal">{{ $line['description'] }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-charcoal">{{ $line['debit'] > 0 ? Money::of($line['debit'])->format(false) : '' }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-charcoal">{{ $line['credit'] > 0 ? Money::of($line['credit'])->format(false) : '' }}</td>
                                    <td class="px-3 py-2 text-right font-mono {{ $line['balance'] > 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ Money::of($line['balance'])->format(false) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                        <tfoot class="border-t border-sand bg-sand/30">
                            <tr>
                                <th scope="row" colspan="4" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.closing_balance') }}</th>
                                <td class="px-3 py-2 text-right font-mono font-bold {{ $closingBalance > 0 ? 'text-heritage-red' : 'text-charcoal' }}">{{ Money::of($closingBalance)->format() }}</td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            @endif
        @endif

        @if ($activeTab === 'invoices' && $canWide)
            @if ($invoices->isEmpty())
                <x-empty-state :message="__('opes.guardian_portal.fees_invoices_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[24rem] border-collapse text-sm">
                        <thead class="border-b border-sand bg-sand/40 text-left">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">Ref</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.fees_due') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.fees_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($invoices as $invoice)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-charcoal/70">{{ $invoice->issue_date }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-charcoal/70">{{ $invoice->invoice_no }}</td>
                                    <td class="px-3 py-2 text-charcoal/70">{{ $invoice->due_date }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-charcoal">{{ Money::of((int) $invoice->total)->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        @endif

        @if ($activeTab === 'receipts')
            @if ($receipts->isEmpty())
                <x-empty-state :message="__('opes.guardian_portal.fees_receipts_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[28rem] border-collapse text-sm">
                        <thead class="border-b border-sand bg-sand/40 text-left">
                            <tr>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.fees_receipt_no') }}</th>
                                <th scope="col" class="px-3 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.fees_method') }}</th>
                                <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardian_portal.fees_amount') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($receipts as $receipt)
                                <tr>
                                    <td class="whitespace-nowrap px-3 py-2 text-charcoal/70">{{ $receipt->value_date }}</td>
                                    <td class="px-3 py-2 font-mono text-xs text-charcoal/70">{{ $receipt->receipt_no }}</td>
                                    <td class="px-3 py-2 text-charcoal/70">{{ $receipt->payment_method }}</td>
                                    <td class="px-3 py-2 text-right font-mono text-charcoal">{{ Money::of($receipt->amount)->format() }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
                @unless ($canWide)
                    <p class="text-xs text-charcoal/50">{{ __('opes.guardian_portal.fees_own_only_note') }}</p>
                @endunless
            @endif
        @endif
    @endif
</div>
