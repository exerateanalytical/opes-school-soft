@php
    // depth => left padding, so a class-4 4111 sits visibly under its class-4
    // 411 parent under its class-4 41 grandparent, without building a full
    // tree widget (task brief: "a simple left-padding by depth is
    // sufficient"). Capped so a runaway depth cannot push the code off-panel.
    $indent = static fn (int $depth): string => 'padding-left: '.min(($depth - 1) * 0.9, 5.4).'rem';
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('archive')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    @if ($canManageAccounts && $showCreateForm)
        <section aria-label="{{ __('opes.ledger_screen.coa_create_form_title') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.ledger_screen.coa_create_form_title') }}</h2>

            <form wire:submit="saveCreateAccount" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="coa-create-parent" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_parent_label') }}</span>
                        <input id="coa-create-parent" type="text" wire:model="createParentCode"
                               placeholder="e.g. 411"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('createParentCode') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_code_label') }}</span>
                        <input id="coa-create-code" type="text" wire:model="createCode"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('createCode') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_name_label') }}</span>
                        <input id="coa-create-name" type="text" wire:model="createName"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('createName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-name-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_name_fr_label') }}</span>
                        <input id="coa-create-name-fr" type="text" wire:model="createNameFr"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('createNameFr') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_type_label') }}</span>
                        <select id="coa-create-type" wire:model="createType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.ledger_screen.choose') }}</option>
                            @foreach ($accountTypeOptions as $option)
                                <option value="{{ $option->value }}">{{ __('opes.ledger_screen.account_type_'.$option->value) }}</option>
                            @endforeach
                        </select>
                        @error('createType') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-normal-balance" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_normal_balance_label') }}</span>
                        <select id="coa-create-normal-balance" wire:model="createNormalBalance"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.ledger_screen.choose') }}</option>
                            @foreach ($normalBalanceOptions as $option)
                                <option value="{{ $option->value }}">{{ __('opes.ledger_screen.normal_balance_'.$option->value) }}</option>
                            @endforeach
                        </select>
                        @error('createNormalBalance') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-create-collective" class="flex items-center gap-2 pt-6">
                        <input id="coa-create-collective" type="checkbox" wire:model="createIsCollective"
                               class="rounded border-border-primary text-primary focus:ring-primary"/>
                        <span class="text-sm text-charcoal">{{ __('opes.ledger_screen.coa_create_collective_label') }}</span>
                    </label>

                    <label for="coa-create-lettrable" class="flex items-center gap-2 pt-6">
                        <input id="coa-create-lettrable" type="checkbox" wire:model="createIsLettrable"
                               class="rounded border-border-primary text-primary focus:ring-primary"/>
                        <span class="text-sm text-charcoal">{{ __('opes.ledger_screen.coa_create_lettrable_label') }}</span>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('opes.ledger_screen.coa_create_submit') }}
                    </button>
                    <button type="button" wire:click="toggleCreateForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        {{ __('opes.ledger_screen.cancel') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($canManageAccounts && $editAccountId !== null)
        <section aria-label="{{ __('opes.ledger_screen.coa_edit_form_title') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.ledger_screen.coa_edit_form_title') }}</h2>

            <form wire:submit="saveEditAccount" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="coa-edit-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_name_label') }}</span>
                        <input id="coa-edit-name" type="text" wire:model="editName"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('editName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-edit-name-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_create_name_fr_label') }}</span>
                        <input id="coa-edit-name-fr" type="text" wire:model="editNameFr"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('editNameFr') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-edit-alias" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_edit_alias_label') }}</span>
                        <input id="coa-edit-alias" type="text" wire:model="editDisplayAlias"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="coa-edit-notes" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_edit_notes_label') }}</span>
                        <input id="coa-edit-notes" type="text" wire:model="editNotes"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('opes.ledger_screen.coa_edit_submit') }}
                    </button>
                    <button type="button" wire:click="cancelEdit"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        {{ __('opes.ledger_screen.cancel') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    @if ($canManageFiscalYears && $showFiscalYearForm)
        <section aria-label="{{ __('opes.ledger_screen.coa_fiscal_year_form_title') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('opes.ledger_screen.coa_fiscal_year_form_title') }}</h2>

            <form wire:submit="saveFiscalYear" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="coa-fy-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_fiscal_year_code_label') }}</span>
                        <input id="coa-fy-code" type="text" wire:model="fiscalYearCode"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fiscalYearCode') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label class="flex items-center gap-2 pt-6">
                        <input type="checkbox" wire:model="fiscalYearIsFirstExercice"
                               class="rounded border-border-primary text-primary focus:ring-primary"/>
                        <span class="text-sm text-charcoal">{{ __('opes.ledger_screen.coa_fiscal_year_first_exercice_label') }}</span>
                    </label>

                    <label for="coa-fy-starts" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_fiscal_year_starts_label') }}</span>
                        <input id="coa-fy-starts" type="date" wire:model="fiscalYearStartsOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fiscalYearStartsOn') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>

                    <label for="coa-fy-ends" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_fiscal_year_ends_label') }}</span>
                        <input id="coa-fy-ends" type="date" wire:model="fiscalYearEndsOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('fiscalYearEndsOn') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        {{ __('opes.ledger_screen.coa_fiscal_year_submit') }}
                    </button>
                    <button type="button" wire:click="toggleFiscalYearForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        {{ __('opes.ledger_screen.cancel') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
    :title="__('opes.ledger_screen.coa_title')"
    :breadcrumb="[__('opes.ledger_screen.breadcrumb_dashboard'), __('opes.ledger_screen.breadcrumb_ledger'), __('opes.ledger_screen.breadcrumb_coa')]"
    :paginator="$accounts"
    :empty-message="__('opes.ledger_screen.coa_empty')"
>
    <x-slot:subnav>@include("livewire.accounting._ledger-subnav")</x-slot:subnav>
    @if ($canManageAccounts || $canManageFiscalYears)
        <x-slot:actions>
            @if ($canManageAccounts)
                <button type="button" wire:click="toggleCreateForm"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showCreateForm ? __('opes.ledger_screen.cancel') : __('opes.ledger_screen.coa_new_account') }}
                </button>
            @endif
            @if ($canManageFiscalYears)
                <button type="button" wire:click="toggleFiscalYearForm"
                        class="rounded border border-primary px-3 py-1.5 text-sm font-medium text-primary hover:bg-primary/10">
                    {{ $showFiscalYearForm ? __('opes.ledger_screen.cancel') : __('opes.ledger_screen.coa_new_fiscal_year') }}
                </button>
            @endif
        </x-slot:actions>
    @endif

    <x-slot:kpis>
        <x-kpi-card :label="__('opes.ledger_screen.kpi_total_accounts')" :value="$totalAccounts" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="16" rx="1.5"/><path stroke-linecap="round" d="M3 9h18M9 9v11"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('opes.ledger_screen.kpi_postable_accounts')" :value="$postableAccounts" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="coa-filter-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_search_label') }}</span>
            <input id="coa-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('opes.ledger_screen.coa_search_placeholder') }}"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="coa-filter-class" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_class_label') }}</span>
            <select id="coa-filter-class" wire:model.live="accountClass"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach (range(1, 9) as $classNumber)
                    <option value="{{ $classNumber }}">{{ __('opes.ledger_screen.coa_class_option', ['n' => $classNumber]) }}</option>
                @endforeach
            </select>
        </label>

        <label for="coa-filter-postable" class="flex items-center gap-2 pb-1.5">
            <input id="coa-filter-postable" type="checkbox" wire:model.live="postableOnly"
                   class="rounded border-border-primary text-primary focus:ring-primary"/>
            <span class="text-sm text-charcoal">{{ __('opes.ledger_screen.coa_postable_only_label') }}</span>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_code') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_name') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_name_fr') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_class') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_type') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_normal_balance') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.coa_column_postable') }}</th>
            @if ($canManageAccounts)
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.ledger_screen.column_actions') }}</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($accounts as $account)
        <tr wire:key="coa-row-{{ $account->id }}">
            <td class="px-4 py-2.5 font-mono text-charcoal" style="{{ $indent($account->depth) }}">{{ $account->code }}</td>
            <td class="px-4 py-2.5 text-charcoal">{{ $account->display_alias ?? $account->name }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $account->name_fr }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $account->account_class }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.ledger_screen.account_type_'.$account->type->value) }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.ledger_screen.normal_balance_'.$account->normal_balance->value) }}</td>
            <td class="px-4 py-2.5">
                @if ($account->is_archived)
                    <x-status-pill status="red" :label="__('opes.ledger_screen.coa_archived')"/>
                @elseif ($account->is_postable)
                    <x-status-pill status="ok" :label="__('opes.ledger_screen.coa_postable_yes')"/>
                @else
                    <x-status-pill status="amber" :label="__('opes.ledger_screen.coa_postable_no')"/>
                @endif
            </td>
            @if ($canManageAccounts)
                <td class="px-4 py-2.5 text-right">
                    <div class="flex justify-end gap-2">
                        <button type="button" wire:click="startEdit({{ $account->id }})"
                                class="text-sm font-medium text-primary hover:underline">
                            {{ __('opes.ledger_screen.coa_edit') }}
                        </button>
                        @if (! $account->is_archived)
                            <button type="button" wire:click="archiveAccount({{ $account->id }})"
                                    wire:confirm="{{ __('opes.ledger_screen.coa_archive_confirm') }}"
                                    class="text-sm font-medium text-heritage-red hover:underline">
                                {{ __('opes.ledger_screen.coa_archive') }}
                            </button>
                        @endif
                    </div>
                </td>
            @endif
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($accounts as $account)
            <article class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="min-w-0" style="{{ $indent($account->depth) }}">
                        <div class="font-mono text-sm font-medium text-charcoal">{{ $account->code }}</div>
                        <div class="truncate text-sm text-charcoal/80">{{ $account->display_alias ?? $account->name }}</div>
                    </div>
                    @if ($account->is_postable)
                        <x-status-pill status="ok" :label="__('opes.ledger_screen.coa_postable_yes')"/>
                    @else
                        <x-status-pill status="amber" :label="__('opes.ledger_screen.coa_postable_no')"/>
                    @endif
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.ledger_screen.coa_column_type') }}</dt>
                        <dd>{{ __('opes.ledger_screen.account_type_'.$account->type->value) }}</dd>
                    </div>
                    <div class="flex justify-between">
                        <dt class="text-charcoal/60">{{ __('opes.ledger_screen.coa_column_normal_balance') }}</dt>
                        <dd>{{ __('opes.ledger_screen.normal_balance_'.$account->normal_balance->value) }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-slot:cards>
    </x-list-screen>
</div>
