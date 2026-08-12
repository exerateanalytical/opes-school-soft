<div class="min-w-0 space-y-4">
    <div class="min-w-0">
        <a href="{{ route('portal.payments') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.payments_title') }}
        </a>

        <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.receipt_title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ $childName }}</p>
    </div>

    <section aria-labelledby="portal-receipt" class="rounded border border-border-primary bg-white shadow-sm">
        <h2 id="portal-receipt" class="sr-only">{{ __('opes.guardian_portal.receipt_title') }}</h2>

        <div class="rounded-t bg-chrome px-4 py-3 text-center">
            <p class="text-sm font-bold tracking-widest text-heritage-yellow">{{ __('opes.shell.brand') }}</p>
            <p class="text-xs text-white/70">{{ __('opes.guardian_portal.receipt_title') }}</p>
        </div>

        <dl class="divide-y divide-border-secondary px-4 text-sm">
            @foreach ([
                [__('opes.guardian_portal.fees_receipt_no'), $receipt->receipt_no],
                [__('opes.guardian_portal.payments_date'), $receipt->value_date],
                [__('opes.guardian_portal.fees_method'), $receipt->payment_method],
                [__('opes.guardian_portal.receipt_payer'), $receipt->payer_name],
                [__('opes.guardian_portal.receipt_reference'), $receipt->reference ?? '—'],
            ] as [$label, $value])
                <div class="flex items-center justify-between gap-4 py-2.5">
                    <dt class="text-charcoal/60">{{ $label }}</dt>
                    <dd class="text-right font-medium text-charcoal">{{ $value }}</dd>
                </div>
            @endforeach

            <div class="flex items-center justify-between gap-4 py-3">
                <dt class="font-semibold text-charcoal">{{ __('opes.guardian_portal.fees_amount') }}</dt>
                <dd class="text-right text-base font-bold tabular-nums text-charcoal">
                    {{ number_format($receipt->amount, 0, ',', ' ') }}
                    <span class="text-xs font-normal text-charcoal/60">{{ $currency }}</span>
                </dd>
            </div>
        </dl>

        {{-- Not a PDF: the only path to a signed document is RenderDocument,
             gated on a staff permission. The number IS what the front desk
             checks, so that is what a parent is given. --}}
        <div class="border-t border-border-secondary p-4">
            <div class="rounded border-2 border-heritage-yellow bg-gold-100 px-4 py-3 text-center">
                <p class="text-xs uppercase tracking-wide text-charcoal/60">
                    {{ __('opes.guardian_portal.receipt_verify') }}
                </p>
                <p class="mt-1 font-mono text-lg font-bold tracking-widest text-primary">
                    {{ $receipt->receipt_no }}
                </p>
            </div>
            <p class="mt-2 text-center text-xs text-charcoal/60">
                {{ __('opes.guardian_portal.receipt_verify_hint') }}
            </p>
        </div>
    </section>
</div>
