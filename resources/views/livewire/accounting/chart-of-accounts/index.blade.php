@php
    // depth => left padding, so a class-4 4111 sits visibly under its class-4
    // 411 parent under its class-4 41 grandparent, without building a full
    // tree widget (task brief: "a simple left-padding by depth is
    // sufficient"). Capped so a runaway depth cannot push the code off-panel.
    $indent = static fn (int $depth): string => 'padding-left: '.min(($depth - 1) * 0.9, 5.4).'rem';
@endphp

<x-list-screen
    :title="__('opes.ledger_screen.coa_title')"
    :breadcrumb="[__('opes.ledger_screen.breadcrumb_dashboard'), __('opes.ledger_screen.breadcrumb_ledger'), __('opes.ledger_screen.breadcrumb_coa')]"
    :paginator="$accounts"
    :empty-message="__('opes.ledger_screen.coa_empty')"
>
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
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="coa-filter-class" class="flex min-w-[9rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.ledger_screen.coa_class_label') }}</span>
            <select id="coa-filter-class" wire:model.live="accountClass"
                    class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach (range(1, 9) as $classNumber)
                    <option value="{{ $classNumber }}">{{ __('opes.ledger_screen.coa_class_option', ['n' => $classNumber]) }}</option>
                @endforeach
            </select>
        </label>

        <label for="coa-filter-postable" class="flex items-center gap-2 pb-1.5">
            <input id="coa-filter-postable" type="checkbox" wire:model.live="postableOnly"
                   class="rounded border-sand text-primary focus:ring-primary"/>
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
        </tr>
    </x-slot:head>

    @foreach ($accounts as $account)
        <tr>
            <td class="px-4 py-2.5 font-mono text-charcoal" style="{{ $indent($account->depth) }}">{{ $account->code }}</td>
            <td class="px-4 py-2.5 text-charcoal">{{ $account->display_alias ?? $account->name }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $account->name_fr }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $account->account_class }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.ledger_screen.account_type_'.$account->type->value) }}</td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ __('opes.ledger_screen.normal_balance_'.$account->normal_balance->value) }}</td>
            <td class="px-4 py-2.5">
                @if ($account->is_postable)
                    <x-status-pill status="ok" :label="__('opes.ledger_screen.coa_postable_yes')"/>
                @else
                    <x-status-pill status="amber" :label="__('opes.ledger_screen.coa_postable_no')"/>
                @endif
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($accounts as $account)
            <article class="rounded border border-sand bg-white p-3">
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
