@php
    use App\Support\Money\Money;

    /**
     * Invoice status -> pill tone. The WORD carries the meaning (09-ui 10);
     * paid-ness is not a status column (04-fees §3.1) so the pill shows the
     * lifecycle status and the Balance column shows the money truth.
     */
    $statusTone = [
        'draft' => 'amber',
        'issued' => 'ok',
        'cancelled' => 'red',
    ];

    $tabs = [
        ['value' => '', 'label' => __('opes.fees_screen.tab_all'), 'count' => $kpis['total']],
        ['value' => 'unpaid', 'label' => __('opes.fees_screen.tab_unpaid'), 'count' => $kpis['unpaid']],
        ['value' => 'paid', 'label' => __('opes.fees_screen.tab_paid'), 'count' => $kpis['total'] - $kpis['unpaid']],
    ];
@endphp

<x-list-screen
    :title="__('opes.fees_screen.invoices_title')"
    :breadcrumb="[__('opes.fees_screen.breadcrumb_dashboard'), __('opes.fees_screen.breadcrumb_finance'), __('opes.fees_screen.breadcrumb_invoices')]"
    :paginator="$invoices"
    :empty-message="__('opes.fees_screen.invoices_empty')"
>
    <x-slot:actions>
        @if ($canConfigureFees)
            <button type="button" wire:click="toggleStructures"
                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ $showStructures ? __('opes.ui.cancel') : __('opes.fees_screen.structures_toggle') }}
            </button>
        @endif
        <button type="button" wire:click="toggleGenerateForm"
                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ $showGenerateForm ? __('opes.ui.cancel') : 'Generate invoices' }}
        </button>
        <button type="button" wire:click="toggleIssueForm"
                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ $showIssueForm ? __('opes.ui.cancel') : 'Issue invoice' }}
        </button>
        <button type="button" wire:click="toggleCreditForm"
                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ $showCreditForm ? __('opes.ui.cancel') : 'Issue credit note' }}
        </button>
        <a href="{{ route('fees.cashier') }}"
           class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
            {{ __('opes.fees_screen.collect_for_student') }}
        </a>
    </x-slot:actions>

    @if ($showStructures && $canConfigureFees)
        <div class="mb-4 rounded border border-border-primary bg-white p-4">
            <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                <h3 class="text-sm font-semibold text-charcoal">{{ __('opes.fees_screen.structures_title') }}</h3>
                <div class="flex gap-2">
                    <button type="button" wire:click="toggleCategoryForm"
                            class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ $showCategoryForm ? __('opes.ui.cancel') : __('opes.fees_screen.new_category') }}
                    </button>
                    <button type="button" wire:click="toggleStructureForm"
                            class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ $showStructureForm ? __('opes.ui.cancel') : __('opes.fees_screen.new_structure') }}
                    </button>
                </div>
            </div>

            @if ($showCategoryForm)
                <form wire:submit.prevent="createCategory" class="mb-4 grid grid-cols-1 gap-3 rounded border border-border-primary bg-cream/40 p-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.category_code') }}</span>
                        <input type="text" wire:model="categoryCode" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('categoryCode') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.category_name') }}</span>
                        <input type="text" wire:model="categoryName" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('categoryName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.category_name_fr') }}</span>
                        <input type="text" wire:model="categoryNameFr" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('categoryNameFr') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.category_display_order') }}</span>
                        <input type="number" min="0" step="1" wire:model="categoryDisplayOrder" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <span class="text-xs text-charcoal/60">{{ __('opes.fees_screen.category_display_order_hint') }}</span>
                        @error('categoryDisplayOrder') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.fees_screen.new_category') }}
                        </button>
                    </div>
                </form>
            @endif

            @if ($showStructureForm)
                <form wire:submit.prevent="createStructure" class="mb-4 grid grid-cols-1 gap-3 rounded border border-border-primary bg-cream/40 p-3 sm:grid-cols-3">
                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_academic_year') }}</span>
                        <select wire:model="structureAcademicYearId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">-</option>
                            @foreach ($academicYearOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('structureAcademicYearId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_school_section') }}</span>
                        <select wire:model="structureSchoolSectionId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">-</option>
                            @foreach ($schoolSectionOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('structureSchoolSectionId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_name') }}</span>
                        <input type="text" wire:model="structureName" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('structureName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_effective_from') }}</span>
                        <input type="date" wire:model="structureEffectiveFrom" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('structureEffectiveFrom') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_fee_item') }}</span>
                        <select wire:model="structureFeeItemId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                            <option value="">-</option>
                            @foreach ($feeItemOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('structureFeeItemId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_line_amount') }}</span>
                        <input type="number" min="0" wire:model="structureLineAmount" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        @error('structureLineAmount') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <p class="text-xs text-charcoal/60 sm:col-span-3">{{ __('opes.fees_screen.structure_simplified_note') }}</p>

                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.fees_screen.new_structure') }}
                        </button>
                    </div>
                </form>
            @endif

            @if (empty($categoryOptions) && !$showCategoryForm)
                <p class="mb-3 text-xs text-charcoal/60">{{ __('opes.fees_screen.no_categories_yet') }}</p>
            @endif

            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead>
                        <tr class="border-b border-border-primary text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                            <th class="px-2 py-2">{{ __('opes.fees_screen.structure_name') }}</th>
                            <th class="px-2 py-2">{{ __('opes.fees_screen.structure_school_section') }}</th>
                            <th class="px-2 py-2">{{ __('opes.fees_screen.column_status') }}</th>
                            <th class="px-2 py-2">{{ __('opes.fees_screen.structure_version') }}</th>
                            <th class="px-2 py-2">{{ __('opes.fees_screen.structure_effective_from') }}</th>
                            <th class="px-2 py-2"><span class="sr-only">{{ __('opes.ui.actions') }}</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @forelse ($structureRows as $structure)
                            <tr wire:key="structure-row-{{ $structure['id'] }}" class="border-b border-border-primary/60">
                                <td class="px-2 py-2 font-medium text-charcoal">{{ $structure['name'] }}</td>
                                <td class="px-2 py-2 text-charcoal/70">{{ $structure['section'] }}</td>
                                <td class="px-2 py-2">
                                    <x-status-pill :status="$structure['status'] === 'active' ? 'ok' : ($structure['status'] === 'archived' ? 'red' : 'amber')"
                                                   :label="__('opes.fees_screen.structure_status_'.$structure['status'])"/>
                                </td>
                                <td class="px-2 py-2 text-charcoal/70">{{ $structure['version'] }}</td>
                                <td class="px-2 py-2 text-charcoal/70">{{ $structure['effective_from'] }}</td>
                                <td class="px-2 py-2 text-right">
                                    <div class="flex justify-end gap-2">
                                        <button type="button" wire:click="toggleEditStructure({{ $structure['id'] }})"
                                                class="text-xs font-medium text-primary hover:underline">
                                            {{ __('opes.ui.edit') }}
                                        </button>
                                        @if ($structure['status'] === 'draft')
                                            <button type="button" wire:click="publishStructure({{ $structure['id'] }})"
                                                    class="text-xs font-medium text-primary hover:underline">
                                                {{ __('opes.fees_screen.structure_publish') }}
                                            </button>
                                        @elseif ($structure['status'] === 'active')
                                            <button type="button" wire:click="archiveStructure({{ $structure['id'] }})"
                                                    class="text-xs font-medium text-heritage-red hover:underline">
                                                {{ __('opes.fees_screen.structure_archive') }}
                                            </button>
                                        @endif
                                    </div>
                                </td>
                            </tr>
                            @if ($showEditForm && $editStructureId === (string) $structure['id'])
                                <tr wire:key="structure-edit-{{ $structure['id'] }}">
                                    <td colspan="6" class="bg-cream/40 px-2 py-3">
                                        <form wire:submit.prevent="saveStructureEdit" class="flex flex-wrap items-end gap-3">
                                            <label class="flex min-w-[14rem] flex-col gap-1">
                                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_name') }}</span>
                                                <input type="text" wire:model="editStructureName" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                                                @error('editStructureName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                                            </label>
                                            <label class="flex flex-col gap-1">
                                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.structure_effective_to') }}</span>
                                                <input type="date" wire:model="editStructureEffectiveTo" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                                            </label>
                                            <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                                {{ __('opes.ui.save') }}
                                            </button>
                                        </form>
                                    </td>
                                </tr>
                            @endif
                        @empty
                            <tr>
                                <td colspan="6" class="px-2 py-4 text-center text-charcoal/60">{{ __('opes.fees_screen.no_structures_yet') }}</td>
                            </tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
            @error('structures') <p class="mt-2 text-xs text-heritage-red">{{ $message }}</p> @enderror
        </div>
    @endif

    @if ($showGenerateForm)
        <div class="mb-4 rounded border border-border-primary bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-charcoal">Generate invoices</h3>
            <form wire:submit.prevent="generateInvoices" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                    <select wire:model="generateAcademicYearId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($academicYearOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('generateAcademicYearId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Fiscal year</span>
                    <select wire:model="generateFiscalYearId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($fiscalYearOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('generateFiscalYearId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Term (optional, annual if blank)</span>
                    <select wire:model="generateTermId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">Annual</option>
                        @foreach ($generateTermOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Class (optional, all classes if blank)</span>
                    <select wire:model="generateClassGroupId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">{{ __('opes.fees_screen.all_classes') }}</option>
                        @foreach ($classOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('generateClassGroupId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Issue date</span>
                    <input type="date" wire:model="generateIssueDate" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    @error('generateIssueDate') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Due date</span>
                    <input type="date" wire:model="generateDueDate" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    @error('generateDueDate') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <div class="sm:col-span-3">
                    <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Generate
                    </button>
                </div>
            </form>
        </div>
    @endif

    @if ($showIssueForm)
        <div class="mb-4 rounded border border-border-primary bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-charcoal">Issue invoice</h3>
            <form wire:submit.prevent="issueInvoice" class="flex flex-wrap items-end gap-3">
                <label class="flex min-w-[16rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Draft invoice</span>
                    <select wire:model="issueInvoiceId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($draftInvoiceOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('issueInvoiceId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    Issue
                </button>
            </form>
        </div>
    @endif

    @if ($showCreditForm)
        <div class="mb-4 rounded border border-border-primary bg-white p-4">
            <h3 class="mb-3 text-sm font-semibold text-charcoal">Issue credit note</h3>
            <form wire:submit.prevent="issueCreditNote" class="grid grid-cols-1 gap-3 sm:grid-cols-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Invoice</span>
                    <select wire:model.live="creditInvoiceId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($issuedInvoiceOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('creditInvoiceId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Invoice line</span>
                    <select wire:model="creditInvoiceLineId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($creditLineOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                    @error('creditInvoiceLineId') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Amount</span>
                    <input type="number" min="1" wire:model="creditAmount" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    @error('creditAmount') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Reason</span>
                    <select wire:model="creditReasonType" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($creditReasonOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    @error('creditReasonType') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Settlement mode</span>
                    <select wire:model="creditSettlementMode" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">-</option>
                        @foreach ($creditSettlementOptions as $option)
                            <option value="{{ $option }}">{{ $option }}</option>
                        @endforeach
                    </select>
                    @error('creditSettlementMode') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Issue date</span>
                    <input type="date" wire:model="creditIssueDate" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    @error('creditIssueDate') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <label class="flex flex-col gap-1 sm:col-span-3">
                    <span class="text-xs font-medium text-charcoal/70">Reason note</span>
                    <textarea wire:model="creditReasonNote" rows="2" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"></textarea>
                    @error('creditReasonNote') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                </label>

                <div class="sm:col-span-3">
                    <button type="submit" class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Issue credit note
                    </button>
                </div>
            </form>
        </div>
    @endif

    {{-- Four KPIs, all dataset-wide numbers from the component's grouped
         queries under the SAME filters minus paidness - nothing invented. --}}
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.fees_screen.kpi_invoices')" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M7 3h10a1 1 0 011 1v17l-3-2-3 2-3-2-3 2V4a1 1 0 011-1z"/><path stroke-linecap="round" d="M9 8h6M9 12h6"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_unpaid')" :value="$kpis['unpaid']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_invoiced_total')" :value="Money::of($kpis['invoiced'])->format(false)" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="6" width="18" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.fees_screen.kpi_outstanding_total')" :value="Money::of($kpis['outstanding'])->format(false)" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3v18M5 8l7-5 7 5M7 21h10"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="invoices-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.status_label') }}</span>
            <select id="invoices-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption }}">{{ __('opes.fees_screen.status_'.$statusOption) }}</option>
                @endforeach
            </select>
        </label>

        <label for="invoices-filter-class" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.class_label') }}</span>
            <select id="invoices-filter-class" wire:model.live="classGroup"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.fees_screen.all_classes') }}</option>
                @foreach ($classOptions as $classOption)
                    <option value="{{ $classOption['id'] }}">{{ $classOption['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="invoices-filter-term" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.fees_screen.term_label') }}</span>
            <select id="invoices-filter-term" wire:model.live="term"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.fees_screen.all_terms') }}</option>
                @foreach ($termOptions as $termOption)
                    <option value="{{ $termOption['id'] }}">{{ $termOption['name'] }}</option>
                @endforeach
            </select>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tab)
            <button type="button" wire:click="selectPaidness('{{ $tab['value'] }}')"
                    @if ($paidness === $tab['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $paidness === $tab['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tab['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_invoice_no') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_student') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_term') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_date') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_total') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_balance') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.fees_screen.column_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">{{ __('opes.fees_screen.print_invoice') }}</span></th>
        </tr>
    </x-slot:head>

    @foreach ($invoices as $row)
        <tr wire:key="invoice-row-{{ $row['id'] }}">
            <td class="px-4 py-2.5 font-mono text-xs text-charcoal/80">{{ $row['invoice_no'] }}</td>
            <td class="px-4 py-2.5">
                <div class="min-w-0">
                    <a href="{{ route('fees.students.statement', ['student' => $row['student_id']]) }}"
                       class="truncate font-medium text-charcoal hover:text-primary">{{ $row['student_name'] }}</a>
                    <div class="truncate font-mono text-xs text-charcoal/60">{{ $row['matricule'] }}</div>
                </div>
            </td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $row['term'] }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $row['date'] }}</td>
            <td class="px-4 py-2.5 text-right font-mono text-charcoal">{{ Money::of($row['total'])->format(false) }}</td>
            <td class="px-4 py-2.5 text-right font-mono {{ $row['outstanding'] > 0 ? 'text-heritage-red' : 'text-charcoal/60' }}">{{ Money::of($row['outstanding'])->format(false) }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$statusTone[$row['status']] ?? 'amber'"
                               :label="__('opes.fees_screen.status_'.$row['status'])"/>
            </td>
            <td class="px-4 py-2.5 text-right">
                {{-- Phase 13 D3 (10-documents §10.2): only an ISSUED invoice
                     has an invoice_no to print - PrintInvoice refuses a draft. --}}
                @if ($row['status'] === 'issued')
                    <a href="{{ route('fees.invoices.print', ['invoice' => $row['id']]) }}" target="_blank" rel="noopener"
                       title="{{ __('opes.fees_screen.print_invoice') }}"
                       class="inline-flex items-center rounded border border-border-primary p-1.5 text-charcoal/60 hover:border-primary/50 hover:text-primary">
                        <span class="sr-only">{{ __('opes.fees_screen.print_invoice') }}</span>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 9V3h12v6M6 18H4a2 2 0 01-2-2v-5a2 2 0 012-2h16a2 2 0 012 2v5a2 2 0 01-2 2h-2M6 14h12v7H6v-7z"/></svg>
                    </a>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($invoices as $row)
            <article wire:key="invoice-card-{{ $row['id'] }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0">
                        <a href="{{ route('fees.students.statement', ['student' => $row['student_id']]) }}"
                           class="font-medium text-charcoal hover:text-primary">{{ $row['student_name'] }}</a>
                        <div class="font-mono text-xs text-charcoal/60">{{ $row['invoice_no'] }}</div>
                    </div>
                    <x-status-pill :status="$statusTone[$row['status']] ?? 'amber'"
                                   :label="__('opes.fees_screen.status_'.$row['status'])"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.column_total') }}</dt>
                        <dd class="font-mono">{{ Money::of($row['total'])->format(false) }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.fees_screen.column_balance') }}</dt>
                        <dd class="font-mono {{ $row['outstanding'] > 0 ? 'text-heritage-red' : '' }}">{{ Money::of($row['outstanding'])->format(false) }}</dd>
                    </div>
                </dl>
                @if ($row['status'] === 'issued')
                    <a href="{{ route('fees.invoices.print', ['invoice' => $row['id']]) }}" target="_blank" rel="noopener"
                       class="mt-2 inline-flex items-center gap-1 text-sm font-medium text-primary underline hover:no-underline">
                        {{ __('opes.fees_screen.print_invoice') }}
                    </a>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
