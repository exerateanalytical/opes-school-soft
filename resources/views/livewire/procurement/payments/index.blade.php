@php
    /** Supplier payment worklist (03-tax-procurement §10): drafts to approve,
        approved to execute, the paid trail; §11.14 SoD lives in the Actions. */
@endphp

<x-list-screen
    :title="__('opes.supplier_payment_screen.title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.supplier_payment_screen.title')]"
    :paginator="$payments"
    :empty-message="__('opes.supplier_payment_screen.empty')"
>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.supplier_payment_screen.kpi_draft')" :value="$kpis['draft']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M11 5h8M4 7l2 2 4-4M11 12h8M11 19h8M4 14l2 2 4-4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_payment_screen.kpi_approved')" :value="$kpis['approved']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_payment_screen.kpi_paid')" :value="$kpis['paid']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 10h18M5 6h14a2 2 0 012 2v8a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.supplier_payment_screen.kpi_withheld')" :value="number_format($kpis['withheld'], 0, ',', ' ')" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 8v8m-4-4h8M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @error('payment')
            <p class="w-full rounded border border-badge-red/40 bg-badge-red/10 px-3 py-2 text-sm text-badge-red">{{ $message }}</p>
        @enderror
        @if (session('status'))
            <p class="w-full rounded border border-badge-blue/40 bg-badge-blue/10 px-3 py-2 text-sm text-charcoal">{{ session('status') }}</p>
        @endif

        <label for="payments-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.search') }}</span>
            <input id="payments-search" type="search" wire:model.live.debounce.300ms="search"
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="payments-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.filter_status') }}</span>
            <select id="payments-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach (['draft', 'approved', 'paid', 'voided'] as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>

        <a href="{{ url('/procurement/payments/pay') }}"
           class="self-end rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.supplier_payment_screen.new_payment') }}
        </a>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_payment_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_date') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_method') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_payment_screen.col_gross') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_payment_screen.col_withheld') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.supplier_payment_screen.col_net') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_status') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.supplier_payment_screen.col_actions') }}</th>
    </x-slot:head>

    @foreach ($payments as $payment)
        <tr wire:key="payment-{{ $payment->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">{{ $payment->payment_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $payment->supplier_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $payment->payment_date }}</td>
            <td class="px-3 py-2 text-sm">{{ str_replace('_', ' ', (string) $payment->payment_method) }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $payment->gross_amount, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $payment->withholding_amount, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ number_format((int) $payment->net_amount, 0, ',', ' ') }}</td>
            <td class="px-3 py-2 text-sm">
                @if ($payment->status === 'voided')
                    <x-status-pill status="red" :label="__('opes.supplier_payment_screen.status_voided')"/>
                @elseif ($payment->status === 'paid')
                    <x-status-pill status="ok" :label="__('opes.supplier_payment_screen.status_paid')"/>
                @else
                    {{ $payment->status }}
                @endif
            </td>
            <td class="px-3 py-2 text-sm">
                @if ($payment->status === 'draft' && $canApprove)
                    <button type="button" wire:click="approve({{ $payment->id }})" class="text-primary hover:underline">
                        {{ __('opes.supplier_payment_screen.approve') }}
                    </button>
                @endif
                @if ($payment->status === 'approved')
                    <button type="button" wire:click="pay({{ $payment->id }})" class="text-primary hover:underline">
                        {{ __('opes.supplier_payment_screen.pay') }}
                    </button>
                @endif
                @if ($payment->status !== 'voided' && $canVoid)
                    <button type="button" wire:click="startVoid({{ $payment->id }})" class="text-badge-red hover:underline">
                        {{ __('opes.supplier_payment_screen.void') }}
                    </button>
                @endif
            </td>
        </tr>
        @if ($voidingId === $payment->id)
            <tr wire:key="payment-void-{{ $payment->id }}" class="border-t border-sand/60 bg-badge-red/5">
                <td colspan="9" class="px-3 py-2">
                    <div class="flex flex-wrap items-end gap-2">
                        <label class="flex grow flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.supplier_payment_screen.void_reason') }}</span>
                            <input type="text" wire:model="voidReason"
                                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="button" wire:click="confirmVoid" class="rounded bg-badge-red px-3 py-1.5 text-sm font-medium text-white">
                            {{ __('opes.supplier_payment_screen.confirm_void') }}
                        </button>
                    </div>
                </td>
            </tr>
        @endif
    @endforeach

    <x-slot:cards>
        @foreach ($payments as $payment)
            <article wire:key="payment-card-{{ $payment->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $payment->payment_no }}</span>
                    <span class="text-xs text-charcoal/60">{{ $payment->status }}</span>
                </div>
                <p class="mt-1 text-sm">{{ $payment->supplier_name }}</p>
                <p class="mt-1 font-mono text-sm">{{ number_format((int) $payment->net_amount, 0, ',', ' ') }} FCFA</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
