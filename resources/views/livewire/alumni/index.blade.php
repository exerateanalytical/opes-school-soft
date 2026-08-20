<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('selectedStudentIds')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- The bulk entry point: graduated students not yet converted. --}}
    @if ($showConvertPanel)
        <section aria-label="{{ __('alumni.convert_title') }}" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">{{ __('alumni.convert_title') }}</h2>
            <p class="mt-1 text-sm text-charcoal/60">{{ __('alumni.convert_intro') }}</p>

            @if ($unconvertedGraduates === [])
                <p class="mt-4 text-sm text-charcoal/70">{{ __('alumni.convert_empty') }}</p>
            @else
                <form wire:submit="convertSelected" class="mt-4 space-y-4">
                    <ul class="max-h-72 divide-y divide-border-primary overflow-y-auto rounded border border-border-primary">
                        @foreach ($unconvertedGraduates as $graduate)
                            <li wire:key="alumni-convert-{{ $graduate->id }}">
                                <label for="alumni-convert-check-{{ $graduate->id }}"
                                       class="flex cursor-pointer items-center gap-3 px-3 py-2 hover:bg-sand/30">
                                    <input id="alumni-convert-check-{{ $graduate->id }}" type="checkbox"
                                           value="{{ $graduate->id }}" wire:model="selectedStudentIds"
                                           class="rounded border-border-primary text-primary focus:ring-primary/50"/>
                                    <span class="text-sm font-medium text-charcoal">{{ $graduate->last_name }} {{ $graduate->first_name }}</span>
                                    <span class="text-xs text-charcoal/60">{{ $graduate->matricule }}</span>
                                </label>
                            </li>
                        @endforeach
                    </ul>

                    <div class="flex items-center gap-3">
                        <button type="submit"
                                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                            {{ __('alumni.convert_selected_button') }}
                        </button>
                        <button type="button" wire:click="toggleConvertPanel"
                                class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                            {{ __('alumni.cancel') }}
                        </button>
                    </div>
                </form>
            @endif
        </section>
    @endif

<x-list-screen
    :title="__('alumni.title')"
    :breadcrumb="[__('alumni.breadcrumb_dashboard'), __('alumni.title')]"
    :paginator="$rows"
    :empty-message="__('alumni.empty')"
>
    <x-slot:actions>
        @can(\App\Modules\Identity\Domain\Permission::AlumniManage->value)
            <button type="button" wire:click="toggleConvertPanel"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showConvertPanel ? __('alumni.convert_hide') : __('alumni.convert_action') }}
            </button>
        @endcan
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card :label="__('alumni.kpi_total')" :value="$kpis['total']" tone="green">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4L2 9l10 5 10-5-10-5z"/><path stroke-linecap="round" d="M6 11.5V16c0 1.5 2.7 3 6 3s6-1.5 6-3v-4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('alumni.kpi_cohort')" :value="$kpis['cohort']" tone="blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="16" rx="2"/><path stroke-linecap="round" d="M8 3v4M16 3v4M3 10h18"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('alumni.kpi_engagements')" :value="$kpis['engagements_this_year']" tone="purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M21 12a9 9 0 11-4.2-7.6"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card :label="__('alumni.kpi_reachable')" :value="$kpis['reachable']" tone="amber">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="alumni-filter-year" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.filter_year') }}</span>
            <select id="alumni-filter-year" wire:model.live="year"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('alumni.all_years') }}</option>
                @foreach ($yearOptions as $option)
                    <option value="{{ $option }}">{{ $option }}</option>
                @endforeach
            </select>
        </label>

        <label for="alumni-filter-occupation" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.filter_occupation') }}</span>
            <input id="alumni-filter-occupation" type="search" wire:model.live.debounce.400ms="occupation"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        <label for="alumni-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('alumni.filter_search') }}</span>
            <input id="alumni-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('alumni.filter_search_placeholder') }}"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_name') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_matricule') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_year') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_final_class') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_occupation') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_engagements') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('alumni.col_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="alumni-row-{{ $row->id }}" class="hover:bg-sand/30">
            <td class="px-4 py-2.5 font-medium text-charcoal">
                <a href="{{ route('alumni.show', $row->id) }}" class="hover:underline">
                    {{ $row->last_name }} {{ $row->first_name }}
                </a>
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->graduation_year }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->final_class_group_name }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->current_occupation ?? __('alumni.none') }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->engagement_count }}</td>
            <td class="px-4 py-2.5">
                @if ($row->is_deceased)
                    <x-status-pill status="red" :label="__('alumni.status_deceased')"/>
                @elseif ($row->contact_email !== null || $row->contact_phone !== null)
                    <x-status-pill status="ok" :label="__('alumni.status_reachable')"/>
                @else
                    <x-status-pill status="amber" :label="__('alumni.status_unreachable')"/>
                @endif
            </td>
            <td class="px-4 py-2.5">
                <a href="{{ route('alumni.show', $row->id) }}" class="font-medium text-primary hover:underline">{{ __('alumni.view') }}</a>
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="alumni-card-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ route('alumni.show', $row->id) }}" class="font-medium text-charcoal hover:underline">
                        {{ $row->last_name }} {{ $row->first_name }}
                    </a>
                    @if ($row->is_deceased)
                        <x-status-pill status="red" :label="__('alumni.status_deceased')"/>
                    @elseif ($row->contact_email !== null || $row->contact_phone !== null)
                        <x-status-pill status="ok" :label="__('alumni.status_reachable')"/>
                    @else
                        <x-status-pill status="amber" :label="__('alumni.status_unreachable')"/>
                    @endif
                </div>
                <p class="mt-1 text-sm text-charcoal/70">
                    {{ $row->graduation_year }} · {{ $row->final_class_group_name }} · {{ $row->current_occupation ?? __('alumni.none') }}
                </p>
            </article>
        @endforeach
    </x-slot:cards>

    <x-slot:rail>
        <div class="space-y-4">
            <section aria-label="{{ __('alumni.title') }}" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">{{ __('alumni.title') }}</h3>
                <ul class="space-y-2 text-sm text-charcoal/80">
                    <li class="flex items-center justify-between"><span>{{ __('alumni.kpi_total') }}</span><span class="tabular-nums">{{ $kpis['total'] }}</span></li>
                    <li class="flex items-center justify-between"><span>{{ __('alumni.kpi_cohort') }}</span><span class="tabular-nums">{{ $kpis['cohort'] }}</span></li>
                    <li class="flex items-center justify-between"><span>{{ __('alumni.kpi_engagements') }}</span><span class="tabular-nums">{{ $kpis['engagements_this_year'] }}</span></li>
                    <li class="flex items-center justify-between"><span>{{ __('alumni.kpi_reachable') }}</span><span class="tabular-nums">{{ $kpis['reachable'] }}</span></li>
                </ul>
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
</div>
