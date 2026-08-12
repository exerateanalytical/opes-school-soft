@php
    use App\Support\Money\Money;

    /*
     * `/portal/children/{s}/fees` - built to mobile/fees-dashboard.png: the
     * dark summary panel, then the statement / invoices / receipts tabs.
     *
     * Two grants of different width. The WIDE one (`receives_invoices OR
     * is_fee_payer`) sees the ledger and every receipt; the row-16 FLOOR sees
     * only this guardian's own payments. The Statement and Invoices tabs are
     * hidden entirely without the wide grant rather than rendered empty - an
     * empty ledger reads as "nothing has ever been billed", which is a very
     * different claim from "the school does not share this with you".
     */
    $billed = collect($statement)->sum('debit');
    $paid = collect($statement)->sum('credit');
    $progress = $billed > 0 ? (int) round(($paid / $billed) * 100) : 0;
@endphp

<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'fees',
    ])

    @if (! $hasEnrollment)
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="card" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.fees_no_enrollment') }}</p>
            </div>
        </x-portal.card>
    @else
        {{-- The dark summary panel the design leads with. --}}
        @if ($canWide)
            <x-portal.card tone="chrome" :padded="false">
                <div class="grid grid-cols-2 divide-x divide-y divide-white/10 sm:grid-cols-4 sm:divide-y-0">
                    <x-portal.stat onDark icon="wallet" tone="primary"
                                   :label="__('opes.guardian_portal.fees_total')"
                                   :value="Money::of((int) $billed)->format()"/>
                    <x-portal.stat onDark icon="check" tone="success"
                                   :label="__('opes.guardian_portal.fees_paid')"
                                   :value="Money::of((int) $paid)->format()"/>
                    <x-portal.stat onDark icon="alert"
                                   :tone="$closingBalance > 0 ? 'danger' : 'success'"
                                   :label="__('opes.guardian_portal.fees_balance')"
                                   :value="Money::of((int) $closingBalance)->format()"/>
                    <x-portal.stat onDark icon="chart" tone="primary"
                                   :label="__('opes.guardian_portal.fees_progress')"
                                   :value="$progress.'%'"/>
                </div>
            </x-portal.card>
        @endif

        {{-- Tab chips. Statement and Invoices appear only with the wide grant. --}}
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="inline-flex gap-2">
                @foreach ([
                    'statement' => __('opes.guardian_portal.fees_tab_statement'),
                    'invoices' => __('opes.guardian_portal.fees_tab_invoices'),
                    'receipts' => __('opes.guardian_portal.fees_tab_receipts'),
                ] as $tab => $label)
                    @if ($tab === 'receipts' || $canWide)
                        <button type="button" wire:click="setTab('{{ $tab }}')"
                                @if ($activeTab === $tab) aria-current="page" @endif
                                @class([
                                    'shrink-0 rounded-xl border px-4 py-2.5 text-sm font-semibold',
                                    'border-portal-green bg-portal-green text-white' => $activeTab === $tab,
                                    'border-border-primary bg-white text-charcoal/70 hover:border-primary/40' => $activeTab !== $tab,
                                ])>
                            {{ $label }}
                        </button>
                    @endif
                @endforeach
            </div>
        </div>

        @if ($activeTab === 'statement' && $canWide)
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.fees_tab_statement')" icon="receipt"/>
                </div>

                @if ($statement === [])
                    <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.fees_statement_empty') }}</p>
                @else
                    <div class="divide-y divide-border-secondary pb-1">
                        @foreach ($statement as $line)
                            <div wire:key="stmt-{{ $loop->index }}" class="flex items-center gap-3 px-4 py-3 sm:px-5">
                                <x-portal.icon :name="$line['debit'] > 0 ? 'card' : 'check'"
                                               :tone="$line['debit'] > 0 ? 'primary' : 'success'" size="sm"/>

                                <div class="min-w-0 flex-1">
                                    <p class="truncate text-sm font-medium text-charcoal">{{ $line['description'] }}</p>
                                    <p class="truncate text-xs text-charcoal/60">
                                        {{ $line['date'] }}@if ($line['reference']) <span aria-hidden="true">&middot;</span> {{ $line['reference'] }}@endif
                                    </p>
                                </div>

                                <span @class([
                                    'shrink-0 text-sm font-semibold tabular-nums',
                                    'text-portal-danger' => $line['debit'] > 0,
                                    'text-portal-success' => $line['credit'] > 0,
                                ])>
                                    {{ $line['debit'] > 0 ? '+' : '−' }}{{ Money::of((int) max($line['debit'], $line['credit']))->format() }}
                                </span>
                            </div>
                        @endforeach
                    </div>
                @endif
            </x-portal.card>
        @endif

        @if ($activeTab === 'invoices' && $canWide)
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.fees_tab_invoices')" icon="card"/>
                </div>

                @if ($invoices->isEmpty())
                    <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.fees_invoices_empty') }}</p>
                @else
                    <div class="divide-y divide-border-secondary pb-1">
                        @foreach ($invoices as $invoice)
                            <x-portal.row wire:key="inv-{{ $invoice->id }}"
                                          :title="$invoice->invoice_no ?? '#'.$invoice->id"
                                          :subtitle="__('opes.guardian_portal.fees_due').' '.$invoice->due_date"
                                          icon="card" tone="primary"
                                          :trailing="Money::of((int) $invoice->total)->format()"
                                          :href="route('portal.children.invoice', [$studentId, $invoice->id])"/>
                        @endforeach
                    </div>
                @endif
            </x-portal.card>
        @endif

        @if ($activeTab === 'receipts')
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.fees_tab_receipts')" icon="receipt"/>
                </div>

                @if ($receipts->isEmpty())
                    <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.fees_receipts_empty') }}</p>
                @else
                    <div class="divide-y divide-border-secondary pb-1">
                        @foreach ($receipts as $receipt)
                            <x-portal.row wire:key="rec-{{ $receipt->id }}"
                                          :title="$receipt->receipt_no"
                                          :subtitle="$receipt->value_date.'  •  '.$receipt->payment_method"
                                          icon="check"
                                          :tone="$receipt->is_own ? 'success' : 'primary'"
                                          :trailing="Money::of((int) $receipt->amount)->format()"
                                          :href="route('portal.children.receipt', [$studentId, $receipt->id])"/>
                        @endforeach
                    </div>
                @endif

                @unless ($canWide)
                    {{-- The row-16 floor. Said plainly so a short list does not
                         read as the school losing a payment. --}}
                    <p class="px-4 pb-4 text-xs text-charcoal/55 sm:px-5">
                        {{ __('opes.guardian_portal.fees_own_only_note') }}
                    </p>
                @endunless
            </x-portal.card>
        @endif
    @endif
</div>
