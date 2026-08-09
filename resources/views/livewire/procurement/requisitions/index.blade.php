@php
    use App\Support\Money\Money;

    $reqTone = [
        'draft' => 'amber', 'submitted' => 'amber', 'approved' => 'ok',
        'partially_ordered' => 'ok', 'ordered' => 'ok', 'rejected' => 'red', 'cancelled' => 'red',
    ];
@endphp

<x-list-screen
    :title="__('opes.procurement_screen.requisitions_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.requisitions_title')]"
    :paginator="$requisitions"
    :empty-message="__('opes.procurement_screen.requisitions_empty')"
>
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_pending_approval')" :value="$kpis['pending']" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_approved')" :value="$kpis['approved']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="requisitions-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_status') }}</span>
            <select id="requisitions-status" wire:model.live="status"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ str_replace('_', ' ', $statusOption) }}</option>
                @endforeach
            </select>
        </label>

        @if ($canApprove)
            <label for="requisitions-reject-reason" class="flex min-w-[14rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.reject_reason') }}</span>
                <input id="requisitions-reject-reason" type="text" wire:model="rejectReason"
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>
        @endif
    </x-slot:filters>

    @if ($warnings !== [])
        <x-slot:tabs>
            <div class="rounded border border-heritage-yellow bg-heritage-yellow/10 p-2 text-sm text-charcoal" role="status">
                <p class="font-medium">{{ __('opes.procurement_screen.budget_warnings') }}</p>
                <ul class="list-inside list-disc">
                    @foreach ($warnings as $warning)
                        <li>{{ $warning }}</li>
                    @endforeach
                </ul>
            </div>
        </x-slot:tabs>
    @endif

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_requisition_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_requested_by') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_requested_on') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.procurement_screen.col_estimated_total') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
        <th class="px-3 py-2 text-left"><span class="sr-only">Actions</span></th>
    </x-slot:head>

    @foreach ($requisitions as $requisition)
        <tr wire:key="requisition-{{ $requisition->id }}" class="border-t border-sand/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">{{ $requisition->requisition_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $requisition->requested_by_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $requisition->requested_on }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ Money::of((int) $requisition->estimated_total)->format(false) }}</td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$reqTone[$requisition->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $requisition->status)"/>
            </td>
            <td class="px-3 py-2 text-sm">
                @if ($requisition->status === 'draft')
                    <button type="button" wire:click="submit({{ $requisition->id }})"
                            class="rounded border border-primary px-2 py-1 text-xs font-medium text-primary hover:bg-primary/10">
                        {{ __('opes.procurement_screen.action_submit') }}
                    </button>
                @elseif ($requisition->status === 'submitted' && $canApprove)
                    <span class="inline-flex gap-1">
                        <button type="button" wire:click="approve({{ $requisition->id }})"
                                class="rounded border border-primary bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            {{ __('opes.procurement_screen.action_approve') }}
                        </button>
                        <button type="button" wire:click="reject({{ $requisition->id }})"
                                class="rounded border border-heritage-red px-2 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                            {{ __('opes.procurement_screen.action_reject') }}
                        </button>
                    </span>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($requisitions as $requisition)
            <article wire:key="requisition-card-{{ $requisition->id }}" class="rounded border border-sand bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $requisition->requisition_no }}</span>
                    <x-status-pill :status="$reqTone[$requisition->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $requisition->status)"/>
                </div>
                <p class="mt-1 text-xs text-charcoal/70">{{ $requisition->requested_by_name }} · {{ $requisition->requested_on }}</p>
                <p class="font-mono text-sm">{{ Money::of((int) $requisition->estimated_total)->format(false) }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
