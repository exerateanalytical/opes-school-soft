@php
    use App\Support\Money\Money;

    $assetTone = [
        'draft' => 'amber',
        'in_progress' => 'amber',
        'in_service' => 'ok',
        'idle' => 'amber',
        'under_maintenance' => 'amber',
        'impaired' => 'red',
        'disposed' => 'red',
        'written_off' => 'red',
        'lost' => 'red',
    ];

    $maintenanceTone = [
        'open' => 'amber',
        'assigned' => 'amber',
        'in_progress' => 'amber',
        'done' => 'ok',
        'cancelled' => 'red',
    ];

    $label = fn (string $value): string => ucfirst(str_replace('_', ' ', $value));
    $fullName = fn (?string $first, ?string $last): string => trim(($first ?? '').' '.($last ?? '')) !== '' ? trim(($first ?? '').' '.($last ?? '')) : '—';
@endphp

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('assets.index') }}" class="hover:text-primary">Assets</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $asset->tag_number }}</span>
            </li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('commissionAsset')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">{{ $message }}</p>
    @enderror

    {{-- ── Header summary ─────────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-start justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <div class="min-w-0">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="text-xl font-semibold text-charcoal">{{ $asset->name }}</h1>
                <x-status-pill :status="$assetTone[$asset->status->value] ?? 'ok'" :label="$label($asset->status->value)"/>
            </div>
            <p class="mt-1 text-sm text-charcoal/70">Tag {{ $asset->tag_number }} · {{ $categoryName }}</p>
            <dl class="mt-3 grid grid-cols-2 gap-x-8 gap-y-2 sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Acquisition cost</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ Money::of((int) $asset->acquisition_cost)->format(false) }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Net book value</dt>
                    <dd class="text-sm font-semibold text-charcoal">{{ $netBookValue !== null ? Money::of($netBookValue)->format(false) : '—' }}</dd>
                </div>
            </dl>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canManage && in_array($asset->status->value, ['draft', 'in_progress'], true))
                <button type="button" wire:click="commissionAsset"
                        wire:confirm="Commission {{ $asset->tag_number }} into service?"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Commission
                </button>
            @endif
            @if ($canDispose && ! in_array($asset->status->value, ['disposed', 'written_off', 'lost'], true))
                <button type="button" wire:click="toggleDisposeForm"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-heritage-red/50 hover:text-heritage-red">
                    {{ $showDisposeForm ? 'Cancel dispose' : 'Dispose' }}
                </button>
            @endif
            <a href="{{ route('assets.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                Back to register
            </a>
        </div>
    </div>

    {{-- ── Inline dispose-asset panel ───────────────────────────────────── --}}
    @if ($showDisposeForm)
        <section aria-label="Dispose asset" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Dispose Asset — {{ $asset->tag_number }}</h2>

            <form wire:submit="saveDisposeAsset" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="dispose-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal type</span>
                        <select id="dispose-type" wire:model="disposeType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($disposalTypeOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="dispose-date" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Disposal date</span>
                        <input id="dispose-date" type="date" wire:model="disposeDate"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('disposeReason')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="dispose-proceeds" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Proceeds (XAF, optional)</span>
                        <input id="dispose-proceeds" type="number" min="0" step="1" wire:model="disposeProceeds"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="dispose-settlement" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Settlement route (required if proceeds &gt; 0)</span>
                        <select id="dispose-settlement" wire:model="disposeSettlement"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">None</option>
                            @foreach ($disposalSettlementOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst(str_replace('_', ' ', $option->value)) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="dispose-buyer" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Buyer partner ID (required for a sale)</span>
                        <input id="dispose-buyer" type="number" min="1" step="1" wire:model="disposeBuyerPartnerId"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="dispose-reason" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Reason</span>
                        <textarea id="dispose-reason" wire:model="disposeReason" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-heritage-red px-4 py-2 text-sm font-semibold text-white hover:bg-heritage-red/90">
                        Dispose asset
                    </button>
                    <button type="button" wire:click="toggleDisposeForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- ── Inline close-maintenance-request panel ───────────────────────── --}}
    @if ($showCloseMaintenanceForm)
        <section aria-label="Close maintenance request" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Close Maintenance Request</h2>

            <form wire:submit="saveCloseMaintenanceRequest" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="close-maintenance-resolution" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Resolution</span>
                        <select id="close-maintenance-resolution" wire:model="closeMaintenanceResolution"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($maintenanceResolutionOptions as $option)
                                <option value="{{ $option->value }}">{{ ucfirst($option->value) }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="close-maintenance-actual-cost" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Actual cost (XAF, required if capitalising)</span>
                        <input id="close-maintenance-actual-cost" type="number" min="0" step="1" wire:model="closeMaintenanceActualCost"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="close-maintenance-capitalise-as" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Capitalise as (if capitalising)</span>
                        <select id="close-maintenance-capitalise-as" wire:model="closeMaintenanceCapitaliseAs"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="increase_cost">Increase cost</option>
                            <option value="component">New component</option>
                        </select>
                    </label>

                    <label for="close-maintenance-justification" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Justification</span>
                        <textarea id="close-maintenance-justification" wire:model="closeMaintenanceJustification" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('closeMaintenanceJustification')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Close request
                    </button>
                    <button type="button" wire:click="toggleCloseMaintenanceForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- ── Acquisition details ────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Acquisition details</h2>
        <dl class="mt-3 grid grid-cols-1 gap-x-8 gap-y-3 sm:grid-cols-3">
            <div>
                <dt class="text-xs font-medium text-charcoal/60">Acquisition date</dt>
                <dd class="text-sm text-charcoal">{{ $asset->acquisition_date ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-charcoal/60">Acquisition cost</dt>
                <dd class="text-sm text-charcoal">{{ Money::of((int) $asset->acquisition_cost)->format(false) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-charcoal/60">Acquisition type</dt>
                <dd class="text-sm text-charcoal">{{ $label($asset->acquisition_type->value) }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-charcoal/60">Supplier</dt>
                <dd class="text-sm text-charcoal">{{ $supplierName ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-charcoal/60">Serial number</dt>
                <dd class="text-sm text-charcoal">{{ $asset->serial_number ?? '—' }}</dd>
            </div>
            <div>
                <dt class="text-xs font-medium text-charcoal/60">In-service date</dt>
                <dd class="text-sm text-charcoal">{{ $asset->in_service_date ?? '—' }}</dd>
            </div>
        </dl>
    </section>

    {{-- ── Depreciation history ───────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Depreciation history</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Fiscal Year</th>
                        <th class="px-2 py-2 text-right">Period</th>
                        <th class="px-2 py-2 text-right">Charge</th>
                        <th class="px-2 py-2 text-right">Accumulated</th>
                        <th class="px-2 py-2 text-right">Net Book Value</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($depreciationHistory as $row)
                        <tr wire:key="dep-{{ $row->id }}">
                            <td class="px-2 py-2 text-charcoal">{{ $row->fiscal_year_code }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $row->period_month }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ Money::of((int) $row->charge)->format(false) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ Money::of((int) $row->closing_accumulated)->format(false) }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ Money::of((int) $row->net_book_value)->format(false) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-2 py-3 text-center text-charcoal/50">No depreciation has been posted for this asset yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Maintenance history ────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Maintenance history</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Title</th>
                        <th class="px-2 py-2">Priority</th>
                        <th class="px-2 py-2">Reported</th>
                        <th class="px-2 py-2 text-right">Estimated Cost</th>
                        <th class="px-2 py-2">Status</th>
                        <th class="px-2 py-2">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($maintenanceHistory as $row)
                        <tr wire:key="mnt-{{ $row->id }}">
                            <td class="px-2 py-2 text-charcoal">{{ $row->title }}</td>
                            <td class="px-2 py-2 capitalize text-charcoal/80">{{ $row->priority }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->reported_at }}</td>
                            <td class="px-2 py-2 text-right tabular-nums">{{ $row->estimated_cost !== null ? Money::of((int) $row->estimated_cost)->format(false) : '—' }}</td>
                            <td class="px-2 py-2">
                                <x-status-pill :status="$maintenanceTone[$row->status] ?? 'amber'" :label="$label($row->status)"/>
                            </td>
                            <td class="px-2 py-2">
                                @if ($canManage && ! in_array($row->status, ['done', 'cancelled'], true))
                                    <button type="button" wire:click="toggleCloseMaintenanceForm({{ $row->id }})"
                                            class="rounded border border-border-primary px-2 py-1 text-xs font-semibold text-charcoal hover:bg-sand/40">
                                        Close
                                    </button>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="px-2 py-3 text-center text-charcoal/50">No maintenance requests recorded for this asset.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Custody movement history ───────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Custody movement history</h2>
        <div class="mt-3 overflow-x-auto">
            <table class="min-w-full divide-y divide-border-primary text-sm">
                <thead>
                    <tr class="text-left text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        <th class="px-2 py-2">Date</th>
                        <th class="px-2 py-2">From</th>
                        <th class="px-2 py-2">To</th>
                        <th class="px-2 py-2">Reason</th>
                        <th class="px-2 py-2">Acknowledged</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border-primary/70">
                    @forelse ($custodyMovements as $row)
                        <tr wire:key="cust-{{ $row->id }}">
                            <td class="px-2 py-2 text-charcoal">{{ $row->moved_on }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $fullName($row->from_first_name, $row->from_last_name) }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $fullName($row->to_first_name, $row->to_last_name) }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->reason }}</td>
                            <td class="px-2 py-2 text-charcoal/80">{{ $row->acknowledged_at ?? 'Pending' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-2 py-3 text-center text-charcoal/50">No custody movements recorded for this asset.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- ── Print Asset Card ───────────────────────────────────────────── --}}
    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5 print:border-0 print:shadow-none" id="asset-card-section">
        <div class="flex items-center justify-between gap-3 print:hidden">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print asset card</h2>
            <div class="flex items-center gap-2">
                <button type="button" onclick="window.print()"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Print
                </button>
                <button type="button" wire:click="exportAssetCardPdf"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Export PDF
                </button>
                <button type="button" wire:click="printLabel"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal transition hover:border-primary/50 hover:text-primary">
                    <span wire:loading.remove wire:target="printLabel">{{ __('opes.assets.print_label') }}</span>
                    <span wire:loading wire:target="printLabel">{{ __('opes.ui.saving') }}</span>
                </button>
                @error('printLabel')
                    <span class="text-xs font-medium text-danger">{{ $message }}</span>
                @enderror
            </div>
        </div>

        <div class="mt-4 mx-auto max-w-sm rounded-lg border-2 border-charcoal/20 p-4 print:mx-0 print:max-w-none print:border-black">
            <p class="text-xs font-semibold uppercase tracking-wide text-charcoal/50">Fixed Asset Tag</p>
            <p class="mt-1 text-lg font-bold text-charcoal">{{ $asset->tag_number }}</p>
            <p class="text-sm text-charcoal">{{ $asset->name }}</p>
            <dl class="mt-3 space-y-1 text-xs text-charcoal/80">
                <div class="flex justify-between gap-2">
                    <dt>Category</dt>
                    <dd class="font-medium">{{ $categoryName }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Acquisition date</dt>
                    <dd class="font-medium">{{ $asset->acquisition_date ?? '—' }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Acquisition cost</dt>
                    <dd class="font-medium">{{ Money::of((int) $asset->acquisition_cost)->format(false) }}</dd>
                </div>
                <div class="flex justify-between gap-2">
                    <dt>Status</dt>
                    <dd class="font-medium">{{ $label($asset->status->value) }}</dd>
                </div>
            </dl>
        </div>
    </section>

    <style>
        @media print {
            body * { visibility: hidden; }
            #asset-card-section, #asset-card-section * { visibility: visible; }
            #asset-card-section { position: absolute; left: 0; top: 0; width: 100%; }
        }
    </style>
</div>
