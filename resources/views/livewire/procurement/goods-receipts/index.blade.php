@php
    $grTone = ['draft' => 'amber', 'confirmed' => 'ok', 'cancelled' => 'red'];
@endphp

<div class="space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

<x-list-screen
    :title="__('opes.procurement_screen.receipts_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.receipts_title')]"
    :paginator="$receipts"
    :empty-message="__('opes.procurement_screen.receipts_empty')"
>
    @if ($canManage)
        <x-slot:actions>
            <button type="button" wire:click="toggleForm"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ $showForm ? 'Cancel' : 'New goods receipt' }}
            </button>
        </x-slot:actions>
    @endif

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_draft_receipts')" :value="$kpis['draft']" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 3l5 5-11 11H5v-5L16 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_discrepancies')" :value="$kpis['discrepancies']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    @if ($showForm)
        <div class="mb-4 rounded border border-border-primary bg-white p-3">
            <p class="mb-2 text-sm font-medium">New goods receipt</p>
            @error('formSupplierId') <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
            @error('formLines') <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
            @error('formReceivedOn') <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror

            <div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Purchase order (optional)</span>
                    <select wire:model.live="formPurchaseOrderId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">—</option>
                        @foreach ($purchaseOrders as $po)
                            <option value="{{ $po->id }}">{{ $po->po_no }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_supplier') }}</span>
                    <select wire:model="formSupplierId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">—</option>
                        @foreach ($suppliers as $supplier)
                            <option value="{{ $supplier->id }}">{{ $supplier->code }} {{ $supplier->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_received_on') }}</span>
                    <input type="date" wire:model="formReceivedOn" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_delivery_note') }}</span>
                    <input type="text" wire:model="formDeliveryNoteRef" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </div>

            <table class="mt-3 w-full text-xs">
                <thead>
                    <tr class="text-left text-charcoal/70">
                        <th class="px-1 py-1">PO line</th>
                        <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_description') }}</th>
                        <th class="px-1 py-1">Qty received</th>
                        <th class="px-1 py-1">Qty rejected</th>
                        <th class="px-1 py-1">Rejection reason</th>
                        <th class="px-1 py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($formLines as $index => $line)
                        <tr wire:key="gr-line-{{ $index }}">
                            <td class="px-1 py-1">
                                <select wire:model="formLines.{{ $index }}.purchase_order_line_id" class="w-full rounded border border-border-primary px-1.5 py-1">
                                    <option value="">—</option>
                                    @foreach ($poLines as $poLine)
                                        <option value="{{ $poLine->id }}">#{{ $poLine->line_no }} {{ $poLine->description }}</option>
                                    @endforeach
                                </select>
                            </td>
                            <td class="px-1 py-1"><input type="text" wire:model="formLines.{{ $index }}.description" class="w-full rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1"><input type="text" wire:model="formLines.{{ $index }}.qty_received" class="w-16 rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1"><input type="text" wire:model="formLines.{{ $index }}.qty_rejected" class="w-16 rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1"><input type="text" wire:model="formLines.{{ $index }}.rejection_reason" class="w-full rounded border border-border-primary px-1.5 py-1"/></td>
                            <td class="px-1 py-1">
                                <button type="button" wire:click="removeLine({{ $index }})" class="text-heritage-red">✕</button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>

            <div class="mt-3 flex flex-wrap gap-2">
                <button type="button" wire:click="addLine" class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                    {{ __('opes.procurement_screen.po_add_line') }}
                </button>
                <button type="button" wire:click="save" class="rounded border border-primary bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90">
                    Save receipt
                </button>
            </div>
        </div>
    @endif

    <x-slot:filters>
        <label for="receipts-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_status') }}</span>
            <select id="receipts-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ $statusOption }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_receipt_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_po_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_received_on') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_delivery_note') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_discrepancy') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
        @if ($canManage)
            <th class="px-3 py-2 text-left"><span class="sr-only">Actions</span></th>
        @endif
    </x-slot:head>

    @foreach ($receipts as $receipt)
        <tr wire:key="receipt-{{ $receipt->id }}" class="border-t border-border-primary/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">{{ $receipt->receipt_no }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->supplier_name }}</td>
            <td class="px-3 py-2 font-mono text-sm">{{ $receipt->po_no ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->received_on }}</td>
            <td class="px-3 py-2 text-sm">{{ $receipt->delivery_note_ref ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$receipt->has_discrepancy ? 'amber' : 'ok'"
                               :label="$receipt->has_discrepancy ? __('opes.procurement_screen.yes') : __('opes.procurement_screen.no')"/>
            </td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$grTone[$receipt->status] ?? 'amber'" :label="(string) $receipt->status"/>
            </td>
            @if ($canManage)
                <td class="px-3 py-2 text-sm">
                    @error('receipt-'.$receipt->id) <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                    @if ($receipt->status === 'draft')
                        <button type="button" wire:click="confirm({{ $receipt->id }})" wire:confirm="Confirm this goods receipt?"
                                class="rounded border border-primary bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90">
                            Confirm
                        </button>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($receipts as $receipt)
            <article wire:key="receipt-card-{{ $receipt->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $receipt->receipt_no }}</span>
                    <x-status-pill :status="$grTone[$receipt->status] ?? 'amber'" :label="(string) $receipt->status"/>
                </div>
                <p class="mt-1 text-sm font-medium">{{ $receipt->supplier_name }}</p>
                <p class="text-xs text-charcoal/70">{{ $receipt->received_on }} · {{ $receipt->po_no ?? '—' }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
