@php
    $itemTone = [
        'active' => 'ok',
        'discontinued' => 'amber',
        'archived' => 'red',
    ];

    $movementLabel = [
        'receipt' => 'Receipt',
        'issue' => 'Issue',
        'transfer_out' => 'Transfer out',
        'transfer_in' => 'Transfer in',
        'adjustment_in' => 'Adjustment in',
        'adjustment_out' => 'Adjustment out',
        'sale' => 'Sale',
        'return_in' => 'Return in',
        'return_out' => 'Return out',
        'opening_balance' => 'Opening balance',
    ];

    $label = fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('inventory.index') }}" class="hover:text-primary">Inventory</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $item->item_code }}</span>
            </li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- ── Header summary ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-charcoal">{{ $item->name }}</h1>
                <x-status-pill :status="$itemTone[$item->status->value] ?? 'ok'" :label="$label($item->status->value)"/>
            </div>
            <p class="mt-1 text-sm text-charcoal/70">Code {{ $item->item_code }} · {{ $item->category?->name ?? '—' }}</p>

            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-4">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Category</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $item->category?->name ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Unit of measure</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $item->unit?->code ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Reorder level</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $item->reorder_level }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Total on hand</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $totalQuantityOnHand }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ route('inventory.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Back to list
            </a>
        </div>
    </div>

    {{-- ── Stock balance per location ─────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Stock balance by location</h2>

        <div class="mt-3 min-w-0 overflow-x-auto rounded border border-border-primary">
            <table class="w-full min-w-[36rem] border-collapse text-sm">
                <thead class="border-b border-border-primary text-left">
                    <tr class="bg-chrome text-white">
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Quantity on hand</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Value on hand</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary">
                    @forelse ($balances as $balance)
                        <tr wire:key="item-balance-{{ $balance['location_id'] }}">
                            <td class="px-4 py-2.5">
                                <div class="font-medium text-charcoal">{{ $balance['location_name'] }}</div>
                                <div class="text-xs text-charcoal/55">{{ $balance['location_code'] }}</div>
                            </td>
                            <td class="px-4 py-2.5 text-charcoal">{{ $balance['quantity_on_hand'] }}</td>
                            <td class="px-4 py-2.5 text-charcoal">{{ number_format($balance['value_on_hand']) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="3" class="px-2 py-3 text-center text-charcoal/50">No stock recorded for this item at any location.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Stock movement history ─────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Stock movement history</h2>

        <div class="mt-3 min-w-0 overflow-x-auto rounded border border-border-primary">
            <table class="w-full min-w-[48rem] border-collapse text-sm">
                <thead class="border-b border-border-primary text-left">
                    <tr class="bg-chrome text-white">
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Quantity</th>
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reference</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary">
                    @forelse ($movements as $movement)
                        <tr wire:key="item-movement-{{ $movement->id }}">
                            <td class="px-4 py-2.5 text-charcoal/70">{{ \Illuminate\Support\Carbon::parse($movement->moved_on)->translatedFormat('d M Y') }}</td>
                            <td class="px-4 py-2.5 text-charcoal">{{ $movement->location_name }}</td>
                            <td class="px-4 py-2.5 text-charcoal">{{ $movementLabel[$movement->movement_type] ?? $movement->movement_type }}</td>
                            <td class="px-4 py-2.5 text-charcoal">{{ $movement->quantity }}</td>
                            <td class="px-4 py-2.5 text-charcoal/70">{{ $movement->document_ref ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-2 py-3 text-center text-charcoal/50">No stock movements recorded for this item.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="mt-3">
            {{ $movements->links() }}
        </div>
    </section>

    {{-- ── Print Item Card ────────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5 print:border-0 print:shadow-none" id="item-card-section">
        <div class="flex items-center justify-between gap-3 print:hidden">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print item card</h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Print
                </button>
                <button type="button" wire:click="exportItemCardPdf"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Export PDF
                </button>
            </div>
        </div>

        <div class="mt-4 mx-auto max-w-sm rounded-lg border-2 border-charcoal/20 p-4 print:mx-0 print:max-w-none print:border-black">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/50">Item Card</p>
            <p class="mt-1 text-lg font-bold text-charcoal">{{ $item->item_code }}</p>
            <p class="text-sm text-charcoal">{{ $item->name }}</p>
            <dl class="mt-3 space-y-1 text-xs text-charcoal/80">
                <div class="flex justify-between gap-2">
                    <dt>Category</dt>
                    <dd class="font-medium">{{ $item->category?->name ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Total quantity on hand</dt>
                    <dd class="font-medium">{{ $totalQuantityOnHand }}</dd>
                </div>
            </dl>
        </div>
    </section>
</div>
