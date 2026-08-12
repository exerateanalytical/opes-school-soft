<div class="min-w-0 space-y-4">
    <div>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.payments_title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('opes.guardian_portal.payments_intro') }}</p>
    </div>

    @if ($payments === [])
        <x-empty-state :message="__('opes.guardian_portal.payments_empty')"/>
    @else
        {{-- Cards on a phone, table from `sm`. --}}
        <div class="space-y-3 sm:hidden">
            @foreach ($payments as $payment)
                <div wire:key="pay-card-{{ $payment['id'] }}" class="rounded border border-border-primary bg-white p-4 shadow-sm">
                    <div class="flex items-start justify-between gap-2">
                        <div class="min-w-0">
                            <p class="font-mono text-sm font-semibold text-charcoal">{{ $payment['receipt_no'] }}</p>
                            <p class="text-xs text-charcoal/60">{{ $payment['child_name'] }}</p>
                            <p class="text-xs text-charcoal/50">{{ $payment['paid_on'] }}</p>
                        </div>
                        <p class="shrink-0 text-right text-sm font-semibold tabular-nums text-charcoal">
                            {{ number_format($payment['amount'], 0, ',', ' ') }}
                            <span class="text-xs font-normal text-charcoal/60">{{ $payment['currency'] }}</span>
                        </p>
                    </div>
                    @if ($payment['is_own'])
                        <span class="mt-2 inline-block rounded bg-success-bg px-2 py-0.5 text-xs font-medium text-success">
                            {{ __('opes.guardian_portal.payments_mine') }}
                        </span>
                    @endif
                </div>
            @endforeach
        </div>

        <div class="hidden overflow-x-auto rounded border border-border-primary bg-white shadow-sm sm:block">
            <table class="w-full text-sm">
                <thead>
                    <tr class="border-b border-border-primary text-left text-xs uppercase tracking-wide text-charcoal/60">
                        <th scope="col" class="px-4 py-2 font-medium">{{ __('opes.guardian_portal.fees_receipt_no') }}</th>
                        <th scope="col" class="px-4 py-2 font-medium">{{ __('opes.guardian_portal.payments_child') }}</th>
                        <th scope="col" class="px-4 py-2 font-medium">{{ __('opes.guardian_portal.payments_date') }}</th>
                        <th scope="col" class="px-4 py-2 font-medium">{{ __('opes.guardian_portal.fees_method') }}</th>
                        <th scope="col" class="px-4 py-2 text-right font-medium">{{ __('opes.guardian_portal.fees_amount') }}</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-secondary">
                    @foreach ($payments as $payment)
                        <tr wire:key="pay-row-{{ $payment['id'] }}">
                            <td class="px-4 py-2 font-mono text-xs text-charcoal">
                                <a href="{{ route('portal.children.receipt', [$payment['student_id'], $payment['id']]) }}"
                                   class="text-primary underline-offset-2 hover:underline">{{ $payment['receipt_no'] }}</a>
                                @if ($payment['is_own'])
                                    <span class="ml-1 rounded bg-success-bg px-1.5 py-0.5 text-[10px] font-medium text-success">
                                        {{ __('opes.guardian_portal.payments_mine') }}
                                    </span>
                                @endif
                            </td>
                            <td class="px-4 py-2 text-charcoal/80">{{ $payment['child_name'] }}</td>
                            <td class="px-4 py-2 whitespace-nowrap text-charcoal/70">{{ $payment['paid_on'] }}</td>
                            <td class="px-4 py-2 text-charcoal/70">{{ $payment['payment_method'] }}</td>
                            <td class="px-4 py-2 text-right font-medium tabular-nums text-charcoal">
                                {{ number_format($payment['amount'], 0, ',', ' ') }}
                                <span class="text-xs font-normal text-charcoal/60">{{ $payment['currency'] }}</span>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        {{-- No download button: a signed receipt is produced by RenderDocument,
             which gates on a staff permission. The number is what the front
             desk actually checks. --}}
        <p class="text-xs text-charcoal/60">{{ __('opes.guardian_portal.payments_verify_note') }}</p>
    @endif
</div>
