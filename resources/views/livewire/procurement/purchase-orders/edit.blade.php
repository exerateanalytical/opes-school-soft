{{--
    PO capture (03-tax-procurement §10). The line grid is ALPINE-LOCAL:
    rows live in the browser, keyboard-first (Enter adds a row), and
    save() ships the whole grid in ONE request - the 01-assessment
    marks-entry discipline.
--}}
<div class="mx-auto max-w-5xl space-y-4 p-4"
     x-data="{
        rows: [{ description: '', quantity: '1', unit_price_ht: 0, discount_rate_bp: 0, expense_account_id: '' }],
        addRow() { this.rows.push({ description: '', quantity: '1', unit_price_ht: 0, discount_rate_bp: 0, expense_account_id: '' }); },
        removeRow(i) { if (this.rows.length > 1) this.rows.splice(i, 1); },
     }">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.procurement_screen.po_edit_title') }}</h1>

    @if ($savedPoNo !== null)
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ __('opes.procurement_screen.po_saved_as') }} <span class="font-mono">{{ $savedPoNo }}</span>
        </p>
    @endif

    @if ($errors->any())
        <ul class="rounded border border-heritage-red/40 bg-heritage-red/10 p-2 text-sm text-heritage-red" role="alert">
            @foreach ($errors->all() as $error)
                <li>{{ $error }}</li>
            @endforeach
        </ul>
    @endif

    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.po_supplier') }}</span>
            <select wire:model="supplierId" class="rounded border border-sand bg-white px-2 py-1.5 text-sm">
                <option value="">—</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->code }} — {{ $supplier->name }}</option>
                @endforeach
            </select>
        </label>

        <label class="flex flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.procurement_screen.po_order_date') }}</span>
            <input type="date" wire:model="orderDate" class="rounded border border-sand bg-white px-2 py-1.5 text-sm"/>
        </label>
    </div>

    <div class="overflow-x-auto rounded border border-sand bg-white">
        <table class="min-w-full text-sm">
            <thead>
                <tr class="text-left text-xs text-charcoal/60">
                    <th class="px-2 py-2">{{ __('opes.procurement_screen.po_line_description') }}</th>
                    <th class="px-2 py-2">{{ __('opes.procurement_screen.po_line_quantity') }}</th>
                    <th class="px-2 py-2">{{ __('opes.procurement_screen.po_line_unit_price') }}</th>
                    <th class="px-2 py-2">{{ __('opes.procurement_screen.po_line_discount') }}</th>
                    <th class="px-2 py-2">{{ __('opes.procurement_screen.po_line_account') }}</th>
                    <th class="px-2 py-2"><span class="sr-only">{{ __('opes.procurement_screen.po_remove_line') }}</span></th>
                </tr>
            </thead>
            <tbody>
                <template x-for="(row, index) in rows" :key="index">
                    <tr class="border-t border-sand/60">
                        <td class="px-2 py-1">
                            <input type="text" x-model="row.description" @keydown.enter.prevent="addRow()"
                                   class="w-full min-w-[12rem] rounded border border-sand px-2 py-1 text-sm"/>
                        </td>
                        <td class="px-2 py-1">
                            <input type="text" inputmode="decimal" x-model="row.quantity"
                                   class="w-20 rounded border border-sand px-2 py-1 text-right font-mono text-sm"/>
                        </td>
                        <td class="px-2 py-1">
                            <input type="number" min="0" x-model.number="row.unit_price_ht"
                                   class="w-28 rounded border border-sand px-2 py-1 text-right font-mono text-sm"/>
                        </td>
                        <td class="px-2 py-1">
                            <input type="number" min="0" max="10000" x-model.number="row.discount_rate_bp"
                                   class="w-24 rounded border border-sand px-2 py-1 text-right font-mono text-sm"/>
                        </td>
                        <td class="px-2 py-1">
                            <select x-model="row.expense_account_id" class="w-40 rounded border border-sand px-2 py-1 text-sm">
                                <option value="">—</option>
                                @foreach ($expenseAccounts as $account)
                                    <option value="{{ $account->id }}">{{ $account->code }} {{ $account->name }}</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="px-2 py-1">
                            <button type="button" @click="removeRow(index)"
                                    class="rounded border border-heritage-red/40 px-2 py-1 text-xs text-heritage-red hover:bg-heritage-red/10">
                                {{ __('opes.procurement_screen.po_remove_line') }}
                            </button>
                        </td>
                    </tr>
                </template>
            </tbody>
        </table>
    </div>

    <div class="flex items-center justify-between">
        <button type="button" @click="addRow()"
                class="rounded border border-sand px-3 py-1.5 text-sm text-charcoal hover:bg-sand/40">
            {{ __('opes.procurement_screen.po_add_line') }}
        </button>

        {{-- ONE request per save: the whole Alpine grid rides along. --}}
        <button type="button" @click="$wire.save(rows)"
                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.procurement_screen.po_save') }}
        </button>
    </div>
</div>
