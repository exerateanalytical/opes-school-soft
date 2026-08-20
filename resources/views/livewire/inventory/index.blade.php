@php
    use App\Support\Money\Money;

    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $itemTone = [
        'active' => 'ok',
        'discontinued' => 'amber',
        'archived' => 'red',
    ];

    $requisitionTone = [
        'draft' => 'amber',
        'submitted' => 'amber',
        'approved' => 'ok',
        'rejected' => 'red',
        'fulfilled' => 'ok',
        'cancelled' => 'red',
    ];

    $stockTakeTone = [
        'draft' => 'amber',
        'counting' => 'amber',
        'counted' => 'amber',
        'approved' => 'ok',
        'posted' => 'ok',
        'cancelled' => 'red',
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

    $tabs = [
        ['value' => 'items', 'label' => 'Items', 'count' => $tabCounts['items']],
        ['value' => 'movements', 'label' => 'Stock Movements', 'count' => $tabCounts['movements']],
        ['value' => 'requisitions', 'label' => 'Requisitions', 'count' => $tabCounts['requisitions']],
        ['value' => 'stock_takes', 'label' => 'Stock Takes', 'count' => $tabCounts['stock_takes']],
    ];
@endphp

@if (session('status'))
    <p class="mb-4 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
        {{ session('status') }}
    </p>
@endif

{{-- Inline "Add/Edit Item" panel - the catalogue door. --}}
@if ($showItemForm)
    <section aria-label="{{ __('opes.inventory_item_form.section_label') }}" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">
            {{ $editingItemId === null ? __('opes.inventory_item_form.heading_create') : __('opes.inventory_item_form.heading_edit') }}
        </h2>

        <form wire:submit="saveItem" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="item-form-code" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.item_code') }}</span>
                    <input id="item-form-code" type="text" wire:model="itemCode" placeholder="ITM0001"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemCode')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-name" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.name') }}</span>
                    <input id="item-form-name" type="text" wire:model="itemName" placeholder="A4 Copier Paper (Box)"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemName')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-category" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.category') }}</span>
                    <select id="item-form-category" wire:model="itemCategoryId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">{{ __('opes.inventory_item_form.select_category') }}</option>
                        @foreach ($categoryOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('itemCategoryId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-unit" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.unit_of_measure') }}</span>
                    <select id="item-form-unit" wire:model="itemUnitOfMeasureId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">{{ __('opes.inventory_item_form.select_unit') }}</option>
                        @foreach ($unitOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('itemUnitOfMeasureId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-type" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.item_type') }}</span>
                    <select id="item-form-type" wire:model="itemType"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="consumable">{{ __('opes.inventory_item_form.type_consumable') }}</option>
                        <option value="equipment">{{ __('opes.inventory_item_form.type_equipment') }}</option>
                        <option value="merchandise">{{ __('opes.inventory_item_form.type_merchandise') }}</option>
                    </select>
                    @error('itemType')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-status" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.status') }}</span>
                    <select id="item-form-status" wire:model="itemStatus"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="active">{{ __('opes.inventory_item_form.status_active') }}</option>
                        <option value="discontinued">{{ __('opes.inventory_item_form.status_discontinued') }}</option>
                        <option value="archived">{{ __('opes.inventory_item_form.status_archived') }}</option>
                    </select>
                    @error('itemStatus')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-reorder-level" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.reorder_level') }}</span>
                    <input id="item-form-reorder-level" type="text" wire:model="itemReorderLevel" placeholder="e.g. 10"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemReorderLevel')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-reorder-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.reorder_quantity') }}</span>
                    <input id="item-form-reorder-quantity" type="text" wire:model="itemReorderQuantity" placeholder="e.g. 50"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemReorderQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-barcode" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.barcode') }}</span>
                    <input id="item-form-barcode" type="text" wire:model="itemBarcode"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemBarcode')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-sale-price" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.sale_price') }}</span>
                    <input id="item-form-sale-price" type="text" wire:model="itemSalePrice" placeholder="e.g. 2500"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemSalePrice')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-description" class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.description') }}</span>
                    <input id="item-form-description" type="text" wire:model="itemDescription"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('itemDescription')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="item-form-stock-tracked" class="flex items-center gap-2 sm:col-span-2">
                    <input id="item-form-stock-tracked" type="checkbox" wire:model="itemIsStockTracked"
                           class="rounded border-border-primary text-primary focus:ring-primary/50"/>
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.inventory_item_form.is_stock_tracked') }}</span>
                </label>

                <div class="flex flex-col gap-2 sm:col-span-2">
                    <label for="item-form-image" class="text-xs font-medium text-charcoal/70">
                        {{ __('opes.inventory_item_form.image') }}
                    </label>

                    <div class="flex flex-wrap items-center gap-3">
                        {{-- wire:key on the wrapper so Livewire replaces the
                             thumbnail when the path changes instead of
                             reusing a stale img. --}}
                        <span wire:key="item-image-preview-{{ $itemImagePath }}"
                              class="flex h-16 w-16 shrink-0 items-center justify-center overflow-hidden rounded border border-border-primary bg-sand">
                            @if ($itemImageUpload !== null)
                                <img src="{{ $itemImageUpload->temporaryUrl() }}" alt="{{ __('opes.inventory_item_form.image_preview') }}"
                                     class="max-h-full max-w-full object-contain"/>
                            @elseif ($itemImagePath !== '')
                                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($itemImagePath) }}"
                                     alt="{{ __('opes.inventory_item_form.image_preview') }}"
                                     class="max-h-full max-w-full object-contain"/>
                            @else
                                <span class="text-xs text-charcoal/40">{{ __('opes.inventory_item_form.no_image') }}</span>
                            @endif
                        </span>

                        <input id="item-form-image" type="file" wire:model="itemImageUpload"
                               accept="image/png,image/jpeg,image/webp"
                               class="text-sm text-charcoal"/>

                        @if ($itemImageUpload !== null || $itemImagePath !== '')
                            <button type="button" wire:click="removeItemImage"
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-heritage-red hover:bg-sand/40">
                                {{ __('opes.inventory_item_form.image_remove') }}
                            </button>
                        @endif
                    </div>

                    <p class="text-xs text-charcoal/60">{{ __('opes.inventory_item_form.image_hint') }}</p>

                    @error('itemImageUpload')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </div>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $editingItemId === null ? __('opes.inventory_item_form.submit_create') : __('opes.inventory_item_form.submit_edit') }}
                </button>
                <button type="button" wire:click="toggleItemForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    {{ __('opes.inventory_item_form.cancel') }}
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Issue Stock" panel. --}}
@if ($showIssueForm)
    <section aria-label="Issue stock" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Issue Stock</h2>

        <form wire:submit="saveIssueStock" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="issue-form-location" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Store location</span>
                    <select id="issue-form-location" wire:model="issueStoreLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('issueStoreLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="issue-form-item" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Item</span>
                    <select id="issue-form-item" wire:model="issueItemId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an item...</option>
                        @foreach ($itemOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('issueItemId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="issue-form-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Quantity</span>
                    <input id="issue-form-quantity" type="text" wire:model="issueQuantity" placeholder="e.g. 10"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('issueQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="issue-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Issued on</span>
                    <input id="issue-form-date" type="date" wire:model="issuedOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('issuedOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="issue-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-medium text-charcoal/70">Notes (optional)</span>
                    <input id="issue-form-notes" type="text" wire:model="issueNotes"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Issue stock
                </button>
                <button type="button" wire:click="toggleIssueForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "New Requisition" panel. --}}
@if ($showRequisitionForm)
    <section aria-label="New requisition" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">New Requisition</h2>

        <form wire:submit="saveRequisition" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="requisition-form-item" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Item</span>
                    <select id="requisition-form-item" wire:model="requisitionItemId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an item...</option>
                        @foreach ($itemOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('requisitionItemId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="requisition-form-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Quantity</span>
                    <input id="requisition-form-quantity" type="text" wire:model="requisitionQuantity" placeholder="e.g. 5"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('requisitionQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="requisition-form-department" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Department (optional)</span>
                    <input id="requisition-form-department" type="text" wire:model="requisitionDepartment" placeholder="e.g. Science Lab"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('requisitionDepartment')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="requisition-form-needed-on" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Needed by (optional)</span>
                    <input id="requisition-form-needed-on" type="date" wire:model="requisitionNeededOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('requisitionNeededOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="requisition-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-medium text-charcoal/70">Notes (optional)</span>
                    <input id="requisition-form-notes" type="text" wire:model="requisitionNotes"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Submit requisition
                </button>
                <button type="button" wire:click="toggleRequisitionForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Receive Stock" panel. --}}
@if ($showReceiveForm)
    <section aria-label="Receive stock" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Receive Stock</h2>

        <form wire:submit="saveReceiveStock" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="receive-form-location" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Store location</span>
                    <select id="receive-form-location" wire:model="receiveStoreLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('receiveStoreLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="receive-form-item" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Item</span>
                    <select id="receive-form-item" wire:model="receiveItemId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an item...</option>
                        @foreach ($itemOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('receiveItemId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="receive-form-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Quantity</span>
                    <input id="receive-form-quantity" type="text" wire:model="receiveQuantity" placeholder="e.g. 10"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('receiveQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="receive-form-unit-cost" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Unit cost (FCFA)</span>
                    <input id="receive-form-unit-cost" type="text" wire:model="receiveUnitCost" placeholder="e.g. 500"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('receiveUnitCost')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="receive-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Received on</span>
                    <input id="receive-form-date" type="date" wire:model="receivedOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('receivedOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="receive-form-document-ref" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Source/supplier reference (optional)</span>
                    <input id="receive-form-document-ref" type="text" wire:model="receiveDocumentRef" placeholder="e.g. Invoice #123"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Receive stock
                </button>
                <button type="button" wire:click="toggleReceiveForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Transfer Stock" panel. --}}
@if ($showTransferForm)
    <section aria-label="Transfer stock" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Transfer Stock</h2>

        <form wire:submit="saveTransferStock" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="transfer-form-from" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">From location</span>
                    <select id="transfer-form-from" wire:model="transferFromLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('transferFromLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="transfer-form-to" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">To location</span>
                    <select id="transfer-form-to" wire:model="transferToLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('transferToLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="transfer-form-item" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Item</span>
                    <select id="transfer-form-item" wire:model="transferItemId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an item...</option>
                        @foreach ($itemOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('transferItemId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="transfer-form-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Quantity</span>
                    <input id="transfer-form-quantity" type="text" wire:model="transferQuantity" placeholder="e.g. 10"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('transferQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="transfer-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Transferred on</span>
                    <input id="transfer-form-date" type="date" wire:model="transferredOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('transferredOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="transfer-form-notes" class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-medium text-charcoal/70">Notes (optional)</span>
                    <input id="transfer-form-notes" type="text" wire:model="transferNotes"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Transfer stock
                </button>
                <button type="button" wire:click="toggleTransferForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Adjust Stock" panel. --}}
@if ($showAdjustForm)
    <section aria-label="Adjust stock" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Adjust Stock</h2>

        <form wire:submit="saveAdjustStock" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="adjust-form-location" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Store location</span>
                    <select id="adjust-form-location" wire:model="adjustStoreLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('adjustStoreLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-item" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Item</span>
                    <select id="adjust-form-item" wire:model="adjustItemId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an item...</option>
                        @foreach ($itemOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('adjustItemId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-direction" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Direction</span>
                    <select id="adjust-form-direction" wire:model="adjustDirection"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="out">Decrease (write-off/damage/loss)</option>
                        <option value="in">Increase (found stock)</option>
                    </select>
                    @error('adjustDirection')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-quantity" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Quantity</span>
                    <input id="adjust-form-quantity" type="text" wire:model="adjustQuantity" placeholder="e.g. 2"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('adjustQuantity')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Adjusted on</span>
                    <input id="adjust-form-date" type="date" wire:model="adjustedOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('adjustedOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-total-cost" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Total cost (FCFA, required only for an increase onto an empty bin)</span>
                    <input id="adjust-form-total-cost" type="text" wire:model="adjustTotalCost" placeholder="e.g. 5000"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('adjustTotalCost')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="adjust-form-reason" class="flex flex-col gap-1 sm:col-span-2">
                    <span class="text-xs font-medium text-charcoal/70">Reason (required)</span>
                    <input id="adjust-form-reason" type="text" wire:model="adjustReason" placeholder="e.g. Damaged in storage"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('adjustReason')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Adjust stock
                </button>
                <button type="button" wire:click="toggleAdjustForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Start Stock Take" panel. --}}
@if ($showStockTakeForm)
    <section aria-label="Start stock take" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Start Stock Take</h2>

        <form wire:submit="saveStartStockTake" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="stock-take-form-location" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Store location</span>
                    <select id="stock-take-form-location" wire:model="stockTakeLocationId"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a location...</option>
                        @foreach ($locationOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('stockTakeLocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="stock-take-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Count date</span>
                    <input id="stock-take-form-date" type="date" wire:model="stockTakeCountDate"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('stockTakeCountDate')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <p class="text-xs text-charcoal/60">Starting a stock take freezes the location's stock movements until the count is recorded, approved and posted.</p>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Start stock take
                </button>
                <button type="button" wire:click="toggleStockTakeForm"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline "Record Counts" panel for the stock take being counted. --}}
@if ($recordingStockTakeId !== null)
    <section aria-label="Record stock take counts" class="mb-4 rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Record Counts</h2>

        @error('stockTakeCounts')
            <p class="mt-2 text-xs text-heritage-red">{{ $message }}</p>
        @enderror

        <form wire:submit="saveRecordCounts" class="mt-4 space-y-4">
            <div class="overflow-x-auto">
                <table class="min-w-full text-sm">
                    <thead>
                        <tr class="bg-chrome text-white">
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">Item</th>
                            <th scope="col" class="px-3 py-2 text-right text-xs font-semibold uppercase tracking-wide">System Qty</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">Counted Qty</th>
                            <th scope="col" class="px-3 py-2 text-left text-xs font-semibold uppercase tracking-wide">Reason (if variance)</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($recordingLines as $line)
                            <tr wire:key="stock-take-line-{{ $line['item_id'] }}" class="border-b border-border-primary">
                                <td class="px-3 py-2 font-medium text-charcoal">{{ $line['item_code'] }} · {{ $line['name'] }}</td>
                                <td class="px-3 py-2 text-right tabular-nums text-charcoal/70">{{ $line['system_quantity'] }}</td>
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="stockTakeCounts.{{ $line['item_id'] }}.counted_quantity"
                                           placeholder="e.g. 10"
                                           class="w-28 rounded border border-border-primary bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50"/>
                                </td>
                                <td class="px-3 py-2">
                                    <input type="text" wire:model="stockTakeCounts.{{ $line['item_id'] }}.reason_code"
                                           placeholder="optional"
                                           class="w-40 rounded border border-border-primary bg-white px-2 py-1 text-sm text-charcoal focus:border-primary/50"/>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Save counts
                </button>
                <button type="button" wire:click="cancelRecordingCounts"
                        class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

<x-list-screen
    title="Inventory"
    :breadcrumb="['Dashboard', 'Inventory']"
    :paginator="$rows"
    empty-message="No inventory records match these filters yet. Items, movements and requisitions appear here as they are set up."
>
    <x-slot:actions>
        @can(\App\Modules\Inventory\Domain\InventoryPermission::MANAGE)
            <button type="button" wire:click="toggleItemForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showItemForm ? __('opes.inventory_item_form.hide_form') : __('opes.inventory_item_form.new_item') }}
            </button>
            <button type="button" wire:click="toggleReceiveForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showReceiveForm ? 'Hide receive form' : 'Receive Stock' }}
            </button>
        @endcan
        @can(\App\Modules\Inventory\Domain\InventoryPermission::POST)
            <button type="button" wire:click="toggleIssueForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showIssueForm ? 'Hide issue form' : 'Issue Stock' }}
            </button>
            <button type="button" wire:click="toggleTransferForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showTransferForm ? 'Hide transfer form' : 'Transfer Stock' }}
            </button>
            <button type="button" wire:click="toggleAdjustForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showAdjustForm ? 'Hide adjust form' : 'Adjust Stock' }}
            </button>
            <button type="button" wire:click="toggleStockTakeForm"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal hover:bg-sand/40">
                {{ $showStockTakeForm ? 'Hide stock take form' : 'Start Stock Take' }}
            </button>
        @endcan
        @can(\App\Modules\Inventory\Domain\InventoryPermission::MANAGE)
            <button type="button" wire:click="toggleRequisitionForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showRequisitionForm ? 'Hide requisition form' : 'New Requisition' }}
            </button>
        @endcan
    </x-slot:actions>

    {{-- Four KPI cards: total items, below reorder level, movements this
         month, pending requisitions - dataset-wide numbers. --}}
    {{-- FIVE, matching the reference. "Movements This Month" and "Pending
         Requisitions" used to sit here too and were removed, not lost: both
         are already tab counts a few pixels below ("Stock Movements 14",
         "Requisitions 0"), so the strip was spending two of its seven tiles
         restating the row under it - and seven tiles wrap onto a second
         row. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Items" :value="$kpis['total_items']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 10h16M10 10v10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Below Reorder Level" :value="$kpis['below_reorder']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.86l-8.2 14.2A1.5 1.5 0 003.5 20.3h17a1.5 1.5 0 001.4-2.24l-8.2-14.2a1.5 1.5 0 00-2.6 0z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.inventory_screen.kpi_stock_value')"
                    :value="\App\Support\Money\Money::of($kpis['stock_value'])->format(false)"
                    :sub="__('opes.inventory_screen.kpi_stock_value_sub')" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 6h18l-1.5 12.5a2 2 0 01-2 1.75H6.5a2 2 0 01-2-1.75z"/><path stroke-linecap="round" d="M8.5 9.5a3.5 3.5 0 007 0"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.inventory_screen.kpi_out_of_stock')" :value="$kpis['out_of_stock']"
                    :sub="__('opes.inventory_screen.kpi_out_of_stock_sub')" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M8 12h8"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.inventory_screen.kpi_categories')" :value="$kpis['categories']"
                    :sub="__('opes.inventory_screen.kpi_categories_sub')" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="3" width="7.5" height="7.5" rx="1.5"/><rect x="3" y="13.5" width="7.5" height="7.5" rx="1.5"/><rect x="13.5" y="13.5" width="7.5" height="7.5" rx="1.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($tab === 'items')
            <label for="inventory-filter-category" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Category</span>
                <select id="inventory-filter-category" wire:model.live="category"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All categories</option>
                    @foreach ($categoryOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($tab === 'movements' || $tab === 'stock_takes')
            <label for="inventory-filter-location" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Location</span>
                <select id="inventory-filter-location" wire:model.live="location"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All locations</option>
                    @foreach ($locationOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        @if ($statusOptions !== [])
            <label for="inventory-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="inventory-filter-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="inventory-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="inventory-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search item, code, requisition..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'items')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Item Code</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Category</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Unit</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">On Hand</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Reorder Level</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
            @elseif ($tab === 'movements')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Item</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Quantity</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Value</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reference</th>
            @elseif ($tab === 'requisitions')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Requisition No</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Section</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Department</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Lines</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Needed On</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                @can(\App\Modules\Inventory\Domain\InventoryPermission::POST)
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
                @endcan
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reference</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Location</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Count Date</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Lines</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                @can(\App\Modules\Inventory\Domain\InventoryPermission::POST)
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Actions</th>
                @endcan
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="inventory-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'items')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->item_code }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->category_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->unit_code }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->quantity_on_hand }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->reorder_level }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$itemTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                </td>
                <td class="px-4 py-2.5">
                    <div class="flex items-center gap-2">
                        <a href="{{ route('inventory.items.show', $row->id) }}"
                           class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                            View
                        </a>
                        @can(\App\Modules\Inventory\Domain\InventoryPermission::MANAGE)
                            <button type="button" wire:click="editItem({{ $row->id }})"
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                                {{ __('opes.inventory_item_form.edit') }}
                            </button>
                        @endcan
                    </div>
                </td>
            @elseif ($tab === 'movements')
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->moved_on }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->item_code }} · {{ $row->item_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->location_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $movementLabel[$row->movement_type] ?? $row->movement_type }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->quantity }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ Money::of((int) $row->total_cost)->format(false) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->document_ref ?? '—' }}</td>
            @elseif ($tab === 'requisitions')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->requisition_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->section_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->department ?? '—' }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->lines_count }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->needed_on ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$requisitionTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                </td>
                @can(\App\Modules\Inventory\Domain\InventoryPermission::POST)
                    <td class="px-4 py-2.5">
                        @if ($row->status === 'submitted')
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="approveRequisition({{ $row->id }})"
                                        wire:confirm="Approve requisition {{ $row->requisition_no }}?"
                                        class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                                    Approve
                                </button>
                                <button type="button" wire:click="rejectRequisition({{ $row->id }})"
                                        wire:confirm="Reject requisition {{ $row->requisition_no }}?"
                                        class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-heritage-red hover:bg-sand/40">
                                    Reject
                                </button>
                            </div>
                        @endif
                    </td>
                @endcan
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->reference }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->location_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->count_date }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->lines_count }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$stockTakeTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                </td>
                @can(\App\Modules\Inventory\Domain\InventoryPermission::POST)
                    <td class="px-4 py-2.5">
                        @if ($row->status === 'counting')
                            <button type="button" wire:click="startRecordingCounts({{ $row->id }})"
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                                Record Counts
                            </button>
                        @elseif ($row->status === 'counted')
                            <button type="button" wire:click="approveStockTake({{ $row->id }})"
                                    wire:confirm="Approve stock take {{ $row->reference }}?"
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                                Approve
                            </button>
                        @elseif ($row->status === 'approved')
                            <button type="button" wire:click="postStockTakeVariance({{ $row->id }})"
                                    wire:confirm="Post the variance for stock take {{ $row->reference }}? This adjusts inventory and unfreezes the location."
                                    class="rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-sand/40">
                                Post Variance
                            </button>
                        @endif
                    </td>
                @endcan
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="inventory-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'items')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->name }}</p>
                        <x-status-pill :status="$itemTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->item_code }} · {{ $row->quantity_on_hand }} {{ $row->unit_code }} on hand</p>
                @elseif ($tab === 'movements')
                    <p class="font-medium text-charcoal">{{ $row->item_code }} · {{ $row->moved_on }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $movementLabel[$row->movement_type] ?? $row->movement_type }} · {{ $row->quantity }} @ {{ $row->location_name }}</p>
                @elseif ($tab === 'requisitions')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->requisition_no }}</p>
                        <x-status-pill :status="$requisitionTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->department ?? $row->section_name ?? '—' }} · {{ $row->lines_count }} lines</p>
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->reference }}</p>
                        <x-status-pill :status="$stockTakeTone[$row->status] ?? 'ok'" :label="ucfirst($row->status)"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->location_name }} · {{ $row->count_date }} · {{ $row->lines_count }} lines</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
    <x-slot:rail>
        <div class="space-y-4">
            {{-- The reference's rail: what the stock is DOING, then what has
                 moved. Both read the same stock_balances rows the table pages
                 through, so they cannot disagree with it. --}}
            <x-shell.panel :title="__('opes.inventory_screen.rail_stock_status')">
                <x-shell.donut :slices="$stockStatusDistribution"
                               :centre-value="number_format(collect($stockStatusDistribution)->sum('value'))"
                               :centre-label="__('opes.inventory_screen.rail_units')"
                               stacked
                               :size="132"
                               :thickness="22"
                               class="py-1"/>
            </x-shell.panel>

            <x-shell.panel :title="__('opes.inventory_screen.rail_recent_movements')">
                @if ($recentMovements === [])
                    <p class="py-3 text-[13px] text-charcoal/55">{{ __('opes.inventory_screen.rail_no_movements') }}</p>
                @else
                    <ul class="divide-y divide-shell-divider">
                        @foreach ($recentMovements as $movement)
                            <li class="flex items-center gap-2 py-2">
                                {{-- Sign, not just a word: a store keeper
                                     scanning this list is looking for what
                                     LEFT, and a receipt and an issue read the
                                     same at a glance without one. --}}
                                <span class="shrink-0 text-[13px] font-semibold {{ $movement['quantity'] < 0 ? 'text-danger-text' : 'text-success-text' }}">
                                    {{ $movement['quantity'] > 0 ? '+' : '' }}{{ $movement['quantity'] }}
                                </span>
                                <span class="min-w-0 flex-1">
                                    <span class="block truncate text-[13px] text-charcoal">{{ $movement['item'] }}</span>
                                    <span class="block truncate text-[11px] text-charcoal/55">
                                        {{ $movement['kind'] }} &middot;
                                        {{ \Illuminate\Support\Carbon::parse($movement['moved_on'])->translatedFormat('d M Y') }}
                                    </span>
                                </span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-shell.panel>
        </div>
    </x-slot:rail>
</x-list-screen>
