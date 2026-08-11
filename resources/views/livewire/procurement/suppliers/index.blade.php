@php
    /** Supplier list (03-tax-procurement §10): search + state filter. */
@endphp

<div class="space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @if ($canManage && $showForm)
        <section class="rounded border border-border-primary bg-white p-4" aria-label="{{ __('opes.procurement_screen.supplier_profile') }}">
            <h2 class="mb-3 text-sm font-semibold text-charcoal">
                {{ $editingSupplierId === null ? __('opes.procurement_screen.suppliers_title') : __('opes.procurement_screen.supplier_profile') }}
            </h2>

            @if ($errors->any())
                <ul class="mb-3 rounded border border-heritage-red/40 bg-heritage-red/10 p-2 text-sm text-heritage-red" role="alert">
                    @foreach ($errors->all() as $error)
                        <li>{{ $error }}</li>
                    @endforeach
                </ul>
            @endif

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_name') }}</span>
                    <input type="text" wire:model="formName" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.detail_type') }}</span>
                    <select wire:model="formSupplierType" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        @foreach ($supplierTypes as $type)
                            <option value="{{ $type }}">{{ str_replace('_', ' ', $type) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_niu') }}</span>
                    <input type="text" wire:model="formNiu" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.detail_regime') }}</span>
                    <select wire:model="formRegimeFiscal" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="">—</option>
                        @foreach ($regimeFiscalOptions as $regime)
                            <option value="{{ $regime }}">{{ str_replace('_', ' ', $regime) }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.detail_payable_account') }}</span>
                    <select wire:model="formPayableAccountId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="">—</option>
                        @foreach ($payableAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->code }} — {{ $account->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_category') }}</span>
                    <select wire:model="formCategoryId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="">—</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">{{ $category->name }}</option>
                        @endforeach
                    </select>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.detail_payment_terms') }}</span>
                    <input type="number" min="0" wire:model="formPaymentTermsDays" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.col_phone') }}</span>
                    <input type="text" wire:model="formPhone" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
                </label>
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Email</span>
                    <input type="email" wire:model="formEmail" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
                </label>
            </div>

            <div class="mt-3 flex items-center gap-2">
                <button type="button" wire:click="saveSupplier"
                        class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('opes.ui.save') ?? 'Save' }}
                </button>
                <button type="button" wire:click="toggleForm"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal hover:bg-sand/40">
                    {{ __('opes.ui.cancel') ?? 'Cancel' }}
                </button>
            </div>
        </section>
    @endif

    @if ($archivingSupplierId !== null)
        <section class="rounded border border-heritage-red/40 bg-heritage-red/10 p-4">
            <p class="mb-2 text-sm text-charcoal">Archive this supplier?</p>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Reason (optional)</span>
                <input type="text" wire:model="archiveReason" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm"/>
            </label>
            @error('archiveReason') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
            <div class="mt-2 flex items-center gap-2">
                <button type="button" wire:click="archiveSupplier"
                        class="rounded border border-heritage-red bg-heritage-red px-3 py-1.5 text-sm font-medium text-white hover:bg-heritage-red/90">
                    {{ __('opes.procurement_screen.state_archived') }}
                </button>
                <button type="button" wire:click="cancelArchive"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal hover:bg-sand/40">
                    {{ __('opes.ui.cancel') ?? 'Cancel' }}
                </button>
            </div>
        </section>
    @endif

<x-list-screen
    :title="__('opes.procurement_screen.suppliers_title')"
    :breadcrumb="[__('opes.nav.dashboard'), __('opes.procurement_screen.suppliers_title')]"
    :paginator="$suppliers"
    :empty-message="__('opes.procurement_screen.suppliers_empty')"
>
    @if ($canManage)
        <x-slot:actions>
            <button type="button" wire:click="toggleForm"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ $showForm ? (__('opes.ui.cancel') ?? 'Cancel') : (__('opes.ui.new') ?? 'New supplier') }}
            </button>
        </x-slot:actions>
    @endif

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_suppliers')" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 21V8l8-5 8 5v13M9 21v-6h6v6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_active')" :value="$kpis['active']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.procurement_screen.kpi_archived')" :value="$kpis['archived']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="4" rx="1"/><path stroke-linecap="round" d="M5 8v11a1 1 0 001 1h12a1 1 0 001-1V8M10 12h4"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="suppliers-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.suppliers_search') }}</span>
            <input id="suppliers-search" type="search" wire:model.live.debounce.300ms="search"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="suppliers-state" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.filter_state') }}</span>
            <select id="suppliers-state" wire:model.live="state"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                <option value="active">{{ __('opes.procurement_screen.state_active') }}</option>
                <option value="archived">{{ __('opes.procurement_screen.state_archived') }}</option>
            </select>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_code') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_name') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_niu') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_category') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_phone') }}</th>
        <th class="px-3 py-2 text-left">{{ __('opes.procurement_screen.col_status') }}</th>
        @if ($canManage)
            <th class="px-3 py-2 text-left"><span class="sr-only">Actions</span></th>
        @endif
    </x-slot:head>

    @foreach ($suppliers as $supplier)
        <tr wire:key="supplier-{{ $supplier->id }}" class="border-t border-border-primary/60 hover:bg-sand/20">
            <td class="px-3 py-2 font-mono text-sm">
                <a href="{{ url('/procurement/suppliers/'.$supplier->id) }}" class="text-primary hover:underline">{{ $supplier->code }}</a>
            </td>
            <td class="px-3 py-2 text-sm">{{ $supplier->name }}</td>
            <td class="px-3 py-2 font-mono text-sm">{{ $supplier->niu ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $supplier->category_name ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">{{ $supplier->phone ?? '—' }}</td>
            <td class="px-3 py-2 text-sm">
                @if ($supplier->is_archived)
                    <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
                @elseif ($supplier->is_active)
                    <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
                @else
                    <x-status-pill status="amber" :label="str_replace('_', ' ', (string) $supplier->niu_status)"/>
                @endif
            </td>
            @if ($canManage)
                <td class="px-3 py-2 text-sm">
                    <span class="inline-flex gap-1">
                        <button type="button" wire:click="editSupplier({{ $supplier->id }})"
                                class="rounded border border-border-primary px-2 py-1 text-xs text-charcoal hover:bg-sand/40">
                            {{ __('opes.ui.edit') ?? 'Edit' }}
                        </button>
                        @if (! $supplier->is_archived)
                            <button type="button" wire:click="promptArchive({{ $supplier->id }})"
                                    class="rounded border border-heritage-red px-2 py-1 text-xs text-heritage-red hover:bg-heritage-red/10">
                                {{ __('opes.procurement_screen.state_archived') }}
                            </button>
                        @endif
                    </span>
                </td>
            @endif
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($suppliers as $supplier)
            <article wire:key="supplier-card-{{ $supplier->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between">
                    <a href="{{ url('/procurement/suppliers/'.$supplier->id) }}" class="font-mono text-sm text-primary">{{ $supplier->code }}</a>
                    @if ($supplier->is_archived)
                        <x-status-pill status="red" :label="__('opes.procurement_screen.state_archived')"/>
                    @else
                        <x-status-pill status="ok" :label="__('opes.procurement_screen.state_active')"/>
                    @endif
                </div>
                <p class="mt-1 text-sm font-medium text-charcoal">{{ $supplier->name }}</p>
                <p class="text-xs text-charcoal/70">{{ $supplier->niu ?? '—' }} · {{ $supplier->phone ?? '—' }}</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
</div>
