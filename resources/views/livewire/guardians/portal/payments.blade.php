{{--
    `/portal/payments` - built to mobile/payment-history-receipts.png.

    Rows 16 and 17, across every child. Deliberately not child-scoped: row 16
    is granted on any valid link without naming a child, so a parent who
    receives no invoices still has a record of their own money.
--}}
<div class="min-w-0 space-y-5">

    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="receipt" tone="primary"/>
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.payments_title') }}</h1>
            <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.payments_intro') }}</p>
        </div>
    </div>

    @if ($payments === [])
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="receipt" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.payments_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        @php
            $paidTotal = collect($payments)->sum('amount');
            $currency = $payments[0]['currency'] ?? 'XAF';
        @endphp

        {{-- Summary panel, as the fees screens show. --}}
        <x-portal.card tone="chrome" :padded="false">
            <div class="flex divide-x divide-white/10">
                <x-portal.stat onDark icon="check" tone="success"
                               :label="__('opes.guardian_portal.fees_tab_receipts')"
                               :value="(string) count($payments)"/>
                <x-portal.stat onDark icon="wallet" tone="primary"
                               :label="__('opes.guardian_portal.fees_amount')"
                               :value="\App\Support\Money\Money::of((int) $paidTotal)->format()"/>
            </div>
        </x-portal.card>

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.fees_tab_receipts')" icon="receipt"/>
            </div>

            <div class="divide-y divide-border-secondary pb-1">
                @foreach ($payments as $payment)
                    <x-portal.row wire:key="pay-{{ $payment['id'] }}"
                                  :title="$payment['receipt_no']"
                                  :subtitle="$payment['child_name'].'  •  '.$payment['paid_on'].'  •  '.$payment['payment_method']"
                                  icon="check"
                                  :tone="$payment['is_own'] ? 'success' : 'primary'"
                                  :trailing="\App\Support\Money\Money::of((int) $payment['amount'])->format()"
                                  :href="route('portal.children.receipt', [$payment['student_id'], $payment['id']])"/>
                @endforeach
            </div>
        </x-portal.card>

        {{-- No download button: a signed receipt is produced by RenderDocument,
             which gates on a staff permission. The number is what the front
             desk actually checks. --}}
        <p class="px-1 text-xs text-charcoal/55">{{ __('opes.guardian_portal.payments_verify_note') }}</p>
    @endif
</div>
