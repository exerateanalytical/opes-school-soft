@php
    use App\Support\Money\Money;

    $poTone = [
        'draft' => 'amber', 'pending_approval' => 'amber', 'approved' => 'ok', 'sent' => 'ok',
        'partially_received' => 'ok', 'received' => 'ok', 'partially_invoiced' => 'ok',
        'invoiced' => 'ok', 'closed' => 'amber', 'cancelled' => 'red',
    ];
@endphp

<div class="space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

<x-list-screen
    :title="__('opes.procurement_screen.orders_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.orders_title')]"
    :paginator="$orders"
    :empty-message="__('opes.procurement_screen.orders_empty')"
>
    <x-slot:actions>
        <a href="{{ url('/procurement/orders/new') }}"
           class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.procurement_screen.new_order') }}
        </a>
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_open_commitments')" :value="Money::of($openCommitments)->format(false)" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 8l7-5 7 5M7 21h10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_drafts')" :value="$draftCount" icon-bg="bg-heritage-yellow">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 3l5 5-11 11H5v-5L16 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="orders-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_status') }}</span>
            <select id="orders-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ str_replace('_', ' ', $statusOption) }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_po_no') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_supplier') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_order_date') }}</th>
        <th class="px-3 py-2 text-right">{{ __('opes.procurement_screen.col_total_ttc') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_expected') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
        @if ($canApprove || $canManage)
            <th class="px-3 py-2 text-left"><span class="sr-only">Actions</span></th>
        @endif
    </x-slot:head>

    @foreach ($orders as $order)
        <tr wire:key="order-{{ $order->id }}" class="border-t border-border-primary/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm"><a href="{{ url('/procurement/orders/'.$order->id) }}" class="text-primary hover:underline">{{ $order->po_no }}</a></td>
            <td class="px-3 py-2 text-sm">{{ $order->supplier_name }}</td>
            <td class="px-3 py-2 text-sm">{{ $order->order_date }}</td>
            <td class="px-3 py-2 text-right font-mono text-sm">{{ Money::of((int) $order->total_ttc)->format(false) }}</td>
            <td class="px-3 py-2 text-sm">{{ $order->expected_delivery_date ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/>
            </td>
            @if ($canApprove || $canManage)
                <td class="px-3 py-2 text-sm">
                    @error('order-'.$order->id) <p class="mb-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                    <span class="inline-flex flex-wrap gap-1">
                        @if ($canApprove && in_array($order->status, ['draft', 'pending_approval'], true))
                            <button type="button" wire:click="approve({{ $order->id }})" wire:confirm="Approve this purchase order?"
                                    class="rounded border border-primary bg-primary px-2 py-1 text-xs font-medium text-white hover:bg-primary/90">
                                {{ __('opes.procurement_screen.action_approve') }}
                            </button>
                        @endif
                        @if ($canManage && $order->status === 'approved')
                            <button type="button" wire:click="send({{ $order->id }})"
                                    class="rounded border border-primary px-2 py-1 text-xs font-medium text-primary hover:bg-primary/10">
                                Send
                            </button>
                        @endif
                        @if ($canManage && in_array($order->status, ['draft', 'pending_approval'], true))
                            <button type="button" wire:click="cancel({{ $order->id }})" wire:confirm="Cancel this purchase order?"
                                    class="rounded border border-heritage-red px-2 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/10">
                                Cancel
                            </button>
                        @endif
                        @if ($canManage && in_array($order->status, ['approved', 'sent', 'partially_received', 'received', 'partially_invoiced', 'invoiced'], true))
                            <button type="button" wire:click="close({{ $order->id }})" wire:confirm="Close this purchase order?"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                                Close
                            </button>
                        @endif
                        @if ($canApprove && ! in_array($order->status, ['draft', 'pending_approval', 'closed', 'cancelled', 'invoiced'], true))
                            <button type="button" wire:click="startAmend({{ $order->id }})"
                                    class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                                Amend
                            </button>
                        @endif
                    </span>
                </td>
            @endif
        </tr>

        @if ($amendingId === $order->id)
            <tr wire:key="order-amend-{{ $order->id }}" class="border-t border-border-primary/60 bg-sand/10">
                <td colspan="7" class="px-3 py-3">
                    <div class="space-y-3">
                        <p class="text-sm font-medium">Amend {{ $order->po_no }} (version {{ $amendExpectedVersion }})</p>
                        @error('amend') <p class="text-xs text-heritage-red">{{ $message }}</p> @enderror

                        <label class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Reason for amendment</span>
                            <textarea wire:model="amendReason" rows="2"
                                      class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
                            @error('reason') <p class="text-xs text-heritage-red">{{ $message }}</p> @enderror
                        </label>

                        <table class="w-full text-xs">
                            <thead>
                                <tr class="text-left text-charcoal/70">
                                    <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_description') }}</th>
                                    <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_quantity') }}</th>
                                    <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_unit_price') }}</th>
                                    <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_discount') }}</th>
                                    <th class="px-1 py-1">{{ __('opes.procurement_screen.po_line_account') }}</th>
                                    <th class="px-1 py-1"></th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($amendLines as $index => $line)
                                    <tr wire:key="amend-line-{{ $index }}">
                                        <td class="px-1 py-1"><input type="text" wire:model="amendLines.{{ $index }}.description" class="w-full rounded border border-border-primary px-1.5 py-1"/></td>
                                        <td class="px-1 py-1"><input type="text" wire:model="amendLines.{{ $index }}.quantity" class="w-16 rounded border border-border-primary px-1.5 py-1"/></td>
                                        <td class="px-1 py-1"><input type="number" wire:model="amendLines.{{ $index }}.unit_price_ht" class="w-24 rounded border border-border-primary px-1.5 py-1"/></td>
                                        <td class="px-1 py-1"><input type="number" wire:model="amendLines.{{ $index }}.discount_rate_bp" class="w-16 rounded border border-border-primary px-1.5 py-1"/></td>
                                        <td class="px-1 py-1">
                                            <select wire:model="amendLines.{{ $index }}.expense_account_id" class="w-full rounded border border-border-primary px-1.5 py-1">
                                                <option value="">—</option>
                                                @foreach ($expenseAccounts as $account)
                                                    <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                        </td>
                                        <td class="px-1 py-1">
                                            <button type="button" wire:click="removeAmendLine({{ $index }})" class="text-heritage-red">✕</button>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>

                        <div class="flex flex-wrap gap-2">
                            <button type="button" wire:click="addAmendLine" class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/40">
                                {{ __('opes.procurement_screen.po_add_line') }}
                            </button>
                            <button type="button" wire:click="saveAmendment" wire:confirm="Save this amendment?"
                                    class="rounded border border-primary bg-primary px-3 py-1.5 text-xs font-medium text-white hover:bg-primary/90">
                                Save amendment
                            </button>
                            <button type="button" wire:click="cancelAmend" class="rounded border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal hover:bg-sand/40">
                                Cancel
                            </button>
                        </div>
                    </div>
                </td>
            </tr>
        @endif
    @endforeach

    <x-slot:cards>
        @foreach ($orders as $order)
            <article wire:key="order-card-{{ $order->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between">
                    <span class="font-mono text-sm">{{ $order->po_no }}</span>
                    <x-status-pill :status="$poTone[$order->status] ?? 'amber'" :label="str_replace('_', ' ', (string) $order->status)"/>
                </div>
                <p class="mt-1 text-sm font-medium">{{ $order->supplier_name }}</p>
                <p class="text-xs text-charcoal/70">{{ $order->order_date }} · {{ Money::of((int) $order->total_ttc)->format(false) }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
