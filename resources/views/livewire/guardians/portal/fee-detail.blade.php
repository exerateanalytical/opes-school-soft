@php
    use App\Support\Money\Money;

    $titles = [
        'structure' => __('opes.guardian_portal.fees_structure_title'),
        'outstanding' => __('opes.guardian_portal.fees_outstanding_title'),
        'pay' => __('opes.guardian_portal.fees_pay_title'),
    ];

    $progress = $totals['billed'] > 0
        ? (int) round(($totals['paid'] / $totals['billed']) * 100)
        : 0;
@endphp

<div class="min-w-0 space-y-5">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'fees',
    ])

    <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="inline-flex gap-2">
            @foreach (['structure' => 'card', 'outstanding' => 'wallet', 'pay' => 'receipt'] as $key => $icon)
                <a href="{{ route('portal.children.fee-detail', [$studentId, $key]) }}"
                   @if ($view === $key) aria-current="page" @endif
                   @class([
                       'flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2.5 text-sm font-semibold',
                       'border-portal-green bg-portal-green text-white' => $view === $key,
                       'border-border-primary bg-white text-charcoal/70 hover:border-primary/40' => $view !== $key,
                   ])>
                    <x-portal.icon :name="$icon" bare size="sm"/>
                    {{ $titles[$key] }}
                </a>
            @endforeach
        </div>
    </div>

    @if (! $hasEnrollment)
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="card" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.fees_no_enrollment') }}</p>
            </div>
        </x-portal.card>

    {{-- ---------------------------------------------------- structure -- --}}
    @elseif ($view === 'structure')
        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.fees_structure_title')" icon="card"/>
            </div>

            @if ($structure->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.fees_invoices_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary">
                    @foreach ($structure as $line)
                        <x-portal.row wire:key="fs-{{ $loop->index }}"
                                      :title="app()->getLocale() === 'fr' && $line->description_fr ? $line->description_fr : $line->description"
                                      :subtitle="$line->fee_category_code"
                                      icon="card" tone="primary"
                                      :trailing="Money::of((int) $line->amount)->format()"
                                      :chevron="false"/>
                    @endforeach

                    <div class="flex items-center gap-3 bg-portal-tint px-4 py-3 sm:px-5">
                        <span class="min-w-0 flex-1 text-sm font-bold text-charcoal">
                            {{ __('opes.guardian_portal.fees_total') }}
                        </span>
                        <span class="shrink-0 text-base font-bold tabular-nums text-charcoal">
                            {{ Money::of((int) $totals['billed'])->format() }}
                        </span>
                    </div>
                </div>
            @endif

            <p class="px-4 py-4 text-xs text-charcoal/55 sm:px-5">
                {{ __('opes.guardian_portal.fees_structure_note') }}
            </p>
        </x-portal.card>

    {{-- -------------------------------------------------- outstanding -- --}}
    @elseif ($view === 'outstanding')
        <x-portal.card tone="chrome" :padded="false">
            <div class="grid grid-cols-2 divide-x divide-y divide-white/10 sm:grid-cols-4 sm:divide-y-0">
                <x-portal.stat onDark icon="wallet" tone="primary"
                               :label="__('opes.guardian_portal.fees_total')"
                               :value="Money::of((int) $totals['billed'])->format()"/>
                <x-portal.stat onDark icon="check" tone="success"
                               :label="__('opes.guardian_portal.fees_paid')"
                               :value="Money::of((int) $totals['paid'])->format()"/>
                <x-portal.stat onDark icon="alert"
                               :tone="$totals['outstanding'] > 0 ? 'danger' : 'success'"
                               :label="__('opes.guardian_portal.fees_balance')"
                               :value="Money::of((int) $totals['outstanding'])->format()"/>
                <x-portal.stat onDark icon="chart" tone="primary"
                               :label="__('opes.guardian_portal.fees_progress')"
                               :value="$progress.'%'"/>
            </div>
        </x-portal.card>

        @if ($installments->isNotEmpty())
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.fees_due')" icon="calendar"/>
                </div>

                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($installments as $installment)
                        <x-portal.row wire:key="ins-{{ $installment->id }}"
                                      :title="app()->getLocale() === 'fr' && $installment->label_fr ? $installment->label_fr : $installment->label"
                                      :subtitle="__('opes.guardian_portal.fees_due').' '.$installment->due_on"
                                      icon="calendar"
                                      :tone="$installment->status === 'overdue' ? 'danger' : ($installment->status === 'due_soon' ? 'warning' : 'primary')"
                                      :trailing="Money::of((int) $installment->amount)->format()"
                                      :chevron="false"/>
                    @endforeach
                </div>
            </x-portal.card>
        @endif

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.fees_tab_statement')" icon="receipt"/>
            </div>

            @if ($statement->isEmpty())
                <p class="px-4 pb-5 text-sm text-charcoal/60 sm:px-5">{{ __('opes.guardian_portal.fees_statement_empty') }}</p>
            @else
                <div class="divide-y divide-border-secondary pb-1">
                    @foreach ($statement as $line)
                        <x-portal.row wire:key="os-{{ $loop->index }}"
                                      :title="$line->description"
                                      :subtitle="$line->date.($line->reference ? '  •  '.$line->reference : '')"
                                      :icon="$line->debit > 0 ? 'card' : 'check'"
                                      :tone="$line->debit > 0 ? 'primary' : 'success'"
                                      :trailing="Money::of((int) max($line->debit, $line->credit))->format()"
                                      :chevron="false"/>
                    @endforeach
                </div>
            @endif
        </x-portal.card>

    {{-- --------------------------------------------------------- pay -- --}}
    @else
        @if ($step === 'method')
            <x-portal.card :padded="false">
                <div class="p-4 sm:p-5">
                    <x-portal.section :title="__('opes.guardian_portal.fees_pay_method')" icon="card"/>
                </div>

                <div class="divide-y divide-border-secondary">
                    @foreach ([
                        'mtn' => 'MTN Mobile Money',
                        'orange' => 'Orange Money',
                        'card' => 'Card',
                        'bank' => 'Bank transfer',
                    ] as $key => $label)
                        <button type="button" wire:click="chooseMethod('{{ $key }}')"
                                class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-secondary sm:px-5">
                            <span @class([
                                'flex h-5 w-5 shrink-0 items-center justify-center rounded-full border-2',
                                'border-primary border-[6px]' => $method === $key,
                                'border-border-strong' => $method !== $key,
                            ])></span>
                            <span class="min-w-0 flex-1 text-sm font-medium text-charcoal">{{ $label }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="space-y-3 p-4 sm:p-5">
                    <div class="flex items-center justify-between rounded-xl bg-portal-tint px-4 py-3">
                        <span class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.fees_balance') }}</span>
                        <span class="text-base font-bold tabular-nums text-charcoal">
                            {{ Money::of((int) $totals['outstanding'])->format() }}
                        </span>
                    </div>

                    <button type="button" wire:click="submitPayment"
                            class="w-full rounded-xl bg-primary px-4 py-3 text-sm font-semibold text-white hover:bg-portal-green-soft">
                        {{ __('opes.guardian_portal.fees_pay_continue') }}
                    </button>
                </div>
            </x-portal.card>
        @else
            {{-- The honest end of the flow. There is no gateway (spec 1
                 non-goals), so this never pretends to have taken money - a
                 screen that appeared to charge a parent and silently did
                 nothing would be the most damaging thing in this portal. --}}
            <x-portal.card>
                <div class="flex flex-col items-center gap-4 py-6 text-center">
                    <x-portal.icon name="alert" tone="warning" size="lg"/>

                    <p class="text-base font-bold text-charcoal">{{ __('opes.guardian_portal.fees_pay_processing') }}</p>
                    <p class="max-w-sm text-sm text-charcoal/70">{{ __('opes.guardian_portal.fees_pay_unavailable') }}</p>

                    <a href="{{ route('portal.children.fees', $studentId) }}"
                       class="rounded-xl border border-primary px-4 py-2.5 text-sm font-semibold text-primary hover:bg-portal-tint">
                        {{ __('opes.guardian_portal.tab_fees') }}
                    </a>
                </div>
            </x-portal.card>
        @endif
    @endif
</div>
