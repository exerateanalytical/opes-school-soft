{{-- Discipline case list — /welfare/discipline (09-ui §2: reached from
     within, not in the sidebar). No mockup ships for this screen, so it
     mirrors the Attendance Management chrome from the same phase: breadcrumb,
     KPI row, status tabs, filterable table. --}}

@php
    use App\Modules\Identity\Domain\Permission;
    use App\Modules\Welfare\Domain\DisciplineCaseStatus;

    // Status -> pill tone. The WORD carries the meaning (09-ui §10); the
    // colour only reinforces it.
    $statusTone = [
        DisciplineCaseStatus::Open->value => 'red',
        DisciplineCaseStatus::UnderInvestigation->value => 'amber',
        DisciplineCaseStatus::Resolved->value => 'ok',
        DisciplineCaseStatus::Dismissed->value => 'ok',
    ];

    $tabs = [
        ['value' => '', 'label' => __('discipline.tab_all'), 'count' => $totalCases],
        ['value' => 'open', 'label' => __('discipline.status.open'), 'count' => $statusCounts['open'] ?? 0],
        ['value' => 'under_investigation', 'label' => __('discipline.status.under_investigation'), 'count' => $statusCounts['under_investigation'] ?? 0],
        ['value' => 'resolved', 'label' => __('discipline.status.resolved'), 'count' => $statusCounts['resolved'] ?? 0],
        ['value' => 'dismissed', 'label' => __('discipline.status.dismissed'), 'count' => $statusCounts['dismissed'] ?? 0],
    ];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('discipline.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('discipline.breadcrumb_discipline') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('discipline.title') }}</h1>

        @can(Permission::DisciplineManage->value)
            <button type="button" wire:click="toggleOpenForm"
                    class="flex items-center gap-1.5 rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                    <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                </svg>
                {{ __('discipline.open_case') }}
            </button>
        @endcan
    </div>

    {{-- ── KPI row ──────────────────────────────────────────────────────── --}}
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <x-kpi-card :label="__('discipline.kpi_total')" :value="$totalCases" icon-bg="bg-primary"
                    :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M9 12h6m-6 4h6M9 8h6M5 4h14v16H5z&quot;/></svg>'"/>
        <x-kpi-card :label="__('discipline.kpi_open')" :value="$statusCounts['open'] ?? 0" icon-bg="bg-heritage-red"
                    :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z&quot;/></svg>'"/>
        <x-kpi-card :label="__('discipline.kpi_investigating')" :value="$statusCounts['under_investigation'] ?? 0" icon-bg="bg-heritage-yellow"
                    :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M21 21l-4.35-4.35M17 11a6 6 0 11-12 0 6 6 0 0112 0z&quot;/></svg>'"/>
        <x-kpi-card :label="__('discipline.kpi_positive')" :value="$positiveCount" icon-bg="bg-badge-blue"
                    :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M14 9V5a3 3 0 00-3-3l-4 9v11h11.28a2 2 0 002-1.7l1.38-9a2 2 0 00-2-2.3zM7 22H4a2 2 0 01-2-2v-7a2 2 0 012-2h3&quot;/></svg>'"/>
        <x-kpi-card :label="__('discipline.kpi_unacknowledged')" :value="$unacknowledged" icon-bg="bg-charcoal"
                    :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M3 8l9 6 9-6M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z&quot;/></svg>'"/>
    </div>

    {{-- ── Open Case form ───────────────────────────────────────────────── --}}
    @if ($showOpenForm)
        <div class="rounded border border-sand bg-white p-4">
            <h2 class="text-sm font-semibold text-charcoal">{{ __('discipline.open_case') }}</h2>

            <div class="mt-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div>
                    <label for="student-query" class="block text-xs font-medium text-charcoal/70">
                        {{ __('discipline.field_student') }}
                    </label>
                    <input id="student-query" type="text" wire:model.live.debounce.300ms="studentQuery"
                           placeholder="{{ __('discipline.student_search_placeholder') }}"
                           class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>

                    @if ($studentCandidates !== [])
                        <ul class="mt-1 divide-y divide-sand rounded border border-sand bg-white text-sm">
                            @foreach ($studentCandidates as $candidate)
                                <li>
                                    <button type="button"
                                            wire:click="$set('studentId', '{{ $candidate->id }}')"
                                            class="flex w-full items-center justify-between px-3 py-1.5 text-left hover:bg-sand/40 {{ (string) $candidate->id === $studentId ? 'bg-primary/10 font-medium text-primary' : 'text-charcoal' }}">
                                        <span>{{ $candidate->first_name }} {{ $candidate->last_name }}</span>
                                        <span class="text-xs text-charcoal/60">{{ $candidate->matricule }}</span>
                                    </button>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                    @error('studentId') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="form-category" class="block text-xs font-medium text-charcoal/70">
                        {{ __('discipline.field_category') }}
                    </label>
                    <select id="form-category" wire:model="formCategoryId"
                            class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                        <option value="">{{ __('discipline.select_category') }}</option>
                        @foreach ($categories as $category)
                            <option value="{{ $category->id }}">
                                {{ $category->name }} ({{ __('discipline.severity') }} {{ $category->severity }})
                            </option>
                        @endforeach
                    </select>
                    @error('formCategoryId') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="occurred-on" class="block text-xs font-medium text-charcoal/70">
                        {{ __('discipline.field_occurred_on') }}
                    </label>
                    <input id="occurred-on" type="date" wire:model="occurredOn"
                           class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>
                    @error('occurredOn') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>

                <div>
                    <label for="visibility" class="block text-xs font-medium text-charcoal/70">
                        {{ __('discipline.field_visibility') }}
                    </label>
                    <select id="visibility" wire:model="visibility"
                            class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                        <option value="internal">{{ __('discipline.visibility.internal') }}</option>
                        <option value="guardian">{{ __('discipline.visibility.guardian') }}</option>
                    </select>
                    <p class="mt-1 text-xs text-charcoal/50">{{ __('discipline.visibility_hint') }}</p>
                </div>

                <div class="md:col-span-2">
                    <label for="description" class="block text-xs font-medium text-charcoal/70">
                        {{ __('discipline.field_description') }}
                    </label>
                    <textarea id="description" wire:model="description" rows="3"
                              class="mt-1 w-full rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"></textarea>
                    @error('description') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>

                <label class="flex items-center gap-2 text-sm text-charcoal">
                    <input type="checkbox" wire:model="isPositive"
                           class="rounded border-sand text-primary focus:ring-primary"/>
                    {{ __('discipline.field_is_positive') }}
                </label>
            </div>

            <div class="mt-4 flex items-center gap-2">
                <button type="button" wire:click="openCase"
                        class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    {{ __('discipline.save_case') }}
                </button>
                <button type="button" wire:click="toggleOpenForm"
                        class="rounded border border-sand px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50">
                    {{ __('opes.ui.cancel') }}
                </button>
            </div>
        </div>
    @endif

    {{-- ── Status tabs + filters ────────────────────────────────────────── --}}
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div role="tablist" aria-label="{{ __('discipline.tabs_label') }}" class="flex flex-wrap gap-1 rounded border border-sand bg-white p-1">
            @foreach ($tabs as $tab)
                <button type="button" role="tab" wire:key="tab-{{ $tab['value'] === '' ? 'all' : $tab['value'] }}"
                        wire:click="selectStatus('{{ $tab['value'] }}')"
                        aria-selected="{{ $status === $tab['value'] ? 'true' : 'false' }}"
                        class="rounded px-3 py-1.5 text-sm {{ $status === $tab['value'] ? 'bg-primary text-white font-medium' : 'text-charcoal hover:bg-sand/40' }}">
                    {{ $tab['label'] }} ({{ $tab['count'] }})
                </button>
            @endforeach
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <label for="case-search" class="sr-only">{{ __('discipline.search_label') }}</label>
            <input id="case-search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="{{ __('discipline.search_placeholder') }}"
                   class="w-56 rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none"/>

            <label for="category-filter" class="sr-only">{{ __('discipline.field_category') }}</label>
            <select id="category-filter" wire:model.live="categoryId"
                    class="rounded border border-sand px-3 py-1.5 text-sm focus:border-primary focus:outline-none">
                <option value="">{{ __('discipline.all_categories') }}</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    {{-- ── Case table ───────────────────────────────────────────────────── --}}
    <div class="rounded border border-sand bg-white">
        @if ($cases->isEmpty())
            <div class="px-4 py-6">
                <x-empty-state :message="__('discipline.empty')"/>
            </div>
        @else
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-sand text-sm">
                    <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                        <tr>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_date') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_student') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_class') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_category') }}</th>
                            <th scope="col" class="px-4 py-2 text-right">{{ __('discipline.severity') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_kind') }}</th>
                            <th scope="col" class="px-4 py-2">{{ __('discipline.col_status') }}</th>
                            <th scope="col" class="px-4 py-2"><span class="sr-only">{{ __('discipline.view_case') }}</span></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand bg-white">
                        @foreach ($cases as $case)
                            <tr wire:key="case-{{ $case->id }}">
                                <td class="px-4 py-2 text-charcoal/70">{{ $case->occurred_on->toDateString() }}</td>
                                <td class="px-4 py-2">
                                    <span class="font-medium text-charcoal">{{ $case->getAttribute('student_name') }}</span>
                                    <span class="block text-xs text-charcoal/60">{{ $case->getAttribute('student_matricule') }}</span>
                                </td>
                                <td class="px-4 py-2 text-charcoal/70">{{ $case->getAttribute('class_group_name') ?? '—' }}</td>
                                <td class="px-4 py-2 text-charcoal/70">{{ $case->category->name }}</td>
                                <td class="px-4 py-2 text-right">{{ $case->category->severity }}</td>
                                <td class="px-4 py-2">
                                    @if ($case->is_positive)
                                        <span class="inline-flex items-center rounded-full border border-badge-blue/40 bg-badge-blue/10 px-2.5 py-0.5 text-xs font-semibold text-badge-blue">
                                            {{ __('discipline.kind_positive') }}
                                        </span>
                                    @else
                                        <span class="text-xs text-charcoal/60">{{ __('discipline.kind_incident') }}</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    <x-status-pill :status="$statusTone[$case->status->value] ?? 'ok'"
                                                   :label="__('discipline.status.'.$case->status->value)"/>
                                </td>
                                <td class="px-4 py-2 text-right">
                                    <a href="{{ url('/welfare/discipline/'.$case->id) }}"
                                       class="text-sm font-medium text-primary hover:underline">
                                        {{ __('discipline.view_case') }}
                                    </a>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-sand px-4 py-3">
                <p class="text-xs text-charcoal/60">
                    {{ __('opes.ui.showing', ['first' => $cases->firstItem() ?? 0, 'last' => $cases->lastItem() ?? 0, 'total' => $cases->total()]) }}
                </p>
                <x-pagination :paginator="$cases"/>
            </div>
        @endif
    </div>
</div>
