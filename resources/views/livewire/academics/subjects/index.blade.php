{{-- Subject Management (frontend images/subject management.png), composed
     from the same x-list-screen contract as Users. Only real data appears:
     the mockup's Core/Elective/Practical KPI split, category donut, teacher
     assignments and "Recent Activities" feed have no backing entities in
     Phase 1 and are deliberately not rendered rather than faked. --}}

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Inline create/edit panel (this module has no /subjects/create route;
         mutation happens on the list screen itself). --}}
    @if ($showForm && $canManage)
        <section aria-label="{{ $editingId === null ? __('opes.subjects_screen.form_create_title') : __('opes.subjects_screen.form_edit_title') }}"
                 class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">
                {{ $editingId === null ? __('opes.subjects_screen.form_create_title') : __('opes.subjects_screen.form_edit_title') }}
            </h2>

            <form wire:submit="save" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="subject-code" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.code_field') }}</span>
                        <input id="subject-code" type="text" wire:model="subjectCode"
                               placeholder="{{ __('opes.subjects_screen.code_placeholder') }}"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('subjectCode')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="subject-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.name_field') }}</span>
                        <input id="subject-name" type="text" wire:model="subjectName"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('subjectName')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="subject-name-fr" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.name_fr_field') }}</span>
                        <input id="subject-name-fr" type="text" wire:model="subjectNameFr"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('subjectNameFr')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="subject-department" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.column_department') }}</span>
                        <select id="subject-department" wire:model="departmentId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('opes.subjects_screen.no_department') }}</option>
                            @foreach ($departmentNames as $id => $departmentName)
                                <option value="{{ $id }}">{{ $departmentName }}</option>
                            @endforeach
                        </select>
                        @error('departmentId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <label for="subject-active" class="flex items-center gap-2">
                    <input id="subject-active" type="checkbox" wire:model="subjectActive"
                           class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                    <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.status_active') }}</span>
                </label>

                <div class="flex items-center gap-2 border-t border-border-primary pt-4">
                    <button type="submit"
                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.subjects_screen.save') }}
                    </button>
                    <button type="button" wire:click="cancelForm"
                            class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.subjects_screen.cancel') }}
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
        :title="__('opes.subjects_screen.title')"
        :breadcrumb="[__('opes.subjects_screen.breadcrumb_dashboard'), __('opes.subjects_screen.breadcrumb_subjects')]"
        :paginator="$subjects"
        :empty-message="__('opes.subjects_screen.empty')"
    >
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="startCreate"
                        class="flex items-center gap-1.5 rounded border border-chrome bg-chrome px-3 py-1.5 text-sm font-medium text-white hover:bg-chrome-light">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    {{ __('opes.subjects_screen.add_subject') }}
                </button>
            @endif
        </x-slot:actions>

        {{-- One KPI only: Total Subjects is the paginator-independent real
             count. The mockup's Core/Elective/Practical/Teacher tiles need
             fields and entities Phase 1 does not have. --}}
        <x-slot:kpis>
            <x-kpi-card :label="__('opes.subjects_screen.kpi_total')" :value="$totalSubjects"
                        icon-bg="bg-primary" class="col-span-2 sm:col-span-1">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M2 3h6a4 4 0 014 4v14a3 3 0 00-3-3H2V3zM22 3h-6a4 4 0 00-4 4v14a3 3 0 013-3h7V3z"/>
                    </svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="subjects-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.search_label') }}</span>
                <input id="subjects-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="{{ __('opes.subjects_screen.search_placeholder') }}"
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
            </label>

            <label for="subjects-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.status_label') }}</span>
                <select id="subjects-filter-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">{{ __('opes.ui.all') }}</option>
                    <option value="active">{{ __('opes.subjects_screen.status_active') }}</option>
                    <option value="inactive">{{ __('opes.subjects_screen.status_inactive') }}</option>
                </select>
            </label>
        </x-slot:filters>

        <x-slot:head>
            <tr class="bg-chrome text-white">
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_code') }}</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_name') }}</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_name_fr') }}</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_department') }}</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_status') }}</th>
                @if ($canManage)
                    <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_actions') }}</th>
                @endif
            </tr>
        </x-slot:head>

        @foreach ($subjects as $subject)
            <tr wire:key="subject-{{ $subject->id }}">
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $subject->code }}</td>
                <td class="px-4 py-2.5 text-charcoal">{{ $subject->name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $subject->name_fr ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    {{ $subject->department_id !== null ? ($departmentNames[$subject->department_id] ?? '—') : __('opes.subjects_screen.no_department') }}
                </td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$subject->is_active ? 'ok' : 'red'"
                                   :label="$subject->is_active ? __('opes.subjects_screen.status_active') : __('opes.subjects_screen.status_inactive')"/>
                </td>
                @if ($canManage)
                    <td class="px-4 py-2.5">
                        <div class="flex items-center justify-end gap-1">
                            <button type="button" wire:click="toggleAllocations({{ $subject->id }})"
                                    class="rounded px-2 py-1 text-xs font-medium text-charcoal/70 hover:bg-sand hover:text-primary">
                                {{ __('opes.subjects_screen.allocations') }}
                            </button>
                            <button type="button" wire:click="startEdit({{ $subject->id }})"
                                    title="{{ __('opes.subjects_screen.edit') }}"
                                    class="rounded p-1.5 text-charcoal/50 hover:bg-sand hover:text-primary">
                                <span class="sr-only">{{ __('opes.subjects_screen.edit') }}</span>
                                <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M12 20h9M16.5 3.5a2.1 2.1 0 013 3L7 19l-4 1 1-4 12.5-12.5z"/>
                                </svg>
                            </button>
                        </div>
                    </td>
                @endif
            </tr>
            @if ($canManage && $allocationsForSubjectId === $subject->id)
                <tr wire:key="subject-allocations-{{ $subject->id }}">
                    <td colspan="6" class="bg-sand/30 px-4 py-3">
                        {{-- Add-allocation control: AllocateSubject has always
                             existed but nothing on this screen reached it, so
                             an allocation could be edited and never created. --}}
                        <div class="mb-3 flex items-center justify-end">
                            <button type="button" wire:click="toggleNewAllocationForm"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.subjects_screen.add_allocation') }}
                            </button>
                        </div>

                        @if ($showNewAllocationForm)
                            <form wire:submit="saveNewAllocation" class="mb-3 space-y-3 rounded border border-border-primary bg-white p-3">
                                <h3 class="text-sm font-semibold text-charcoal">{{ __('opes.subjects_screen.add_allocation') }}</h3>

                                <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
                                    <label for="new-alloc-level" class="flex flex-col gap-1">
                                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.class_level_field') }}</span>
                                        <select id="new-alloc-level" wire:model="newAllocClassLevelId"
                                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                            <option value="">{{ __('opes.subjects_screen.class_level_placeholder') }}</option>
                                            @foreach ($levelOptions as $level)
                                                <option value="{{ $level->id }}">{{ app()->getLocale() === 'fr' ? $level->name_fr : $level->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('newAllocClassLevelId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                                    </label>

                                    <label for="new-alloc-stream" class="flex flex-col gap-1">
                                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.stream_field') }}</span>
                                        <select id="new-alloc-stream" wire:model="newAllocStreamId"
                                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                            <option value="">{{ __('opes.subjects_screen.stream_whole_level') }}</option>
                                            @foreach ($streamOptions as $stream)
                                                <option value="{{ $stream->id }}">{{ app()->getLocale() === 'fr' ? $stream->name_fr : $stream->name }}</option>
                                            @endforeach
                                        </select>
                                        @error('newAllocStreamId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                                    </label>

                                    <label for="new-alloc-coefficient" class="flex flex-col gap-1">
                                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.coefficient_field') }}</span>
                                        <input id="new-alloc-coefficient" type="number" step="0.01" min="0" max="99.99"
                                               wire:model="newAllocCoefficient"
                                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                        @error('newAllocCoefficient')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                                    </label>
                                </div>

                                <div class="flex flex-wrap items-center gap-4">
                                    <label for="new-alloc-optional" class="flex items-center gap-2">
                                        <input id="new-alloc-optional" type="checkbox" wire:model="newAllocIsOptional"
                                               class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                                        <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.is_optional_field') }}</span>
                                    </label>
                                    <label for="new-alloc-counts" class="flex items-center gap-2">
                                        <input id="new-alloc-counts" type="checkbox" wire:model="newAllocCountsTowardAverage"
                                               class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                                        <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.counts_toward_average_field') }}</span>
                                    </label>
                                </div>

                                <div class="flex items-center gap-2 border-t border-border-primary pt-3">
                                    <button type="submit"
                                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                        {{ __('opes.subjects_screen.save') }}
                                    </button>
                                    <button type="button" wire:click="toggleNewAllocationForm"
                                            class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                        {{ __('opes.subjects_screen.cancel') }}
                                    </button>
                                </div>
                            </form>
                        @endif

                        @if ($expandedAllocations->isEmpty())
                            <p class="text-sm text-charcoal/60">{{ __('opes.subjects_screen.allocations_empty') }}</p>
                        @else
                            <div class="space-y-2">
                                @foreach ($expandedAllocations as $allocation)
                                    <div wire:key="allocation-{{ $allocation->id }}" class="rounded border border-border-primary bg-white p-3">
                                        @if ($editingAllocationId === $allocation->id)
                                            <form wire:submit="saveAllocation" class="space-y-3">
                                                <div class="grid grid-cols-1 gap-x-6 gap-y-3 sm:grid-cols-3">
                                                    <label for="alloc-coefficient-{{ $allocation->id }}" class="flex flex-col gap-1">
                                                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.subjects_screen.coefficient_field') }}</span>
                                                        <input id="alloc-coefficient-{{ $allocation->id }}" type="number" step="0.01" min="0" max="99.99"
                                                               wire:model="allocCoefficient"
                                                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                                                        @error('allocCoefficient')
                                                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                                                        @enderror
                                                    </label>
                                                    <label for="alloc-optional-{{ $allocation->id }}" class="flex items-center gap-2 pt-5">
                                                        <input id="alloc-optional-{{ $allocation->id }}" type="checkbox" wire:model="allocIsOptional"
                                                               class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                                                        <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.is_optional_field') }}</span>
                                                    </label>
                                                    <label for="alloc-counts-{{ $allocation->id }}" class="flex items-center gap-2 pt-5">
                                                        <input id="alloc-counts-{{ $allocation->id }}" type="checkbox" wire:model="allocCountsTowardAverage"
                                                               class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                                                        <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.counts_toward_average_field') }}</span>
                                                    </label>
                                                </div>
                                                <label for="alloc-active-{{ $allocation->id }}" class="flex items-center gap-2">
                                                    <input id="alloc-active-{{ $allocation->id }}" type="checkbox" wire:model="allocIsActive"
                                                           class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                                                    <span class="text-sm text-charcoal">{{ __('opes.subjects_screen.status_active') }}</span>
                                                </label>
                                                <div class="flex items-center gap-2 border-t border-border-primary pt-3">
                                                    <button type="submit"
                                                            class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                                                        {{ __('opes.subjects_screen.save') }}
                                                    </button>
                                                    <button type="button" wire:click="cancelAllocationForm"
                                                            class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                                        {{ __('opes.subjects_screen.cancel') }}
                                                    </button>
                                                </div>
                                            </form>
                                        @else
                                            <div class="flex items-center justify-between gap-2">
                                                <div class="text-sm text-charcoal">
                                                    <span class="font-medium">
                                                        {{ $allocation->classLevel !== null ? (app()->getLocale() === 'fr' ? $allocation->classLevel->name_fr : $allocation->classLevel->name) : '—' }}
                                                    </span>
                                                    <span class="text-charcoal/60">— {{ __('opes.subjects_screen.coefficient_field') }}: {{ $allocation->coefficient }}</span>
                                                </div>
                                                <button type="button" wire:click="startEditAllocation({{ $allocation->id }})"
                                                        class="text-sm font-medium text-primary hover:underline">
                                                    {{ __('opes.subjects_screen.edit') }}
                                                </button>
                                            </div>
                                        @endif
                                    </div>
                                @endforeach
                            </div>
                        @endif
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:cards>
            @foreach ($subjects as $subject)
                <article wire:key="subject-card-{{ $subject->id }}" class="rounded border border-border-primary bg-white p-3">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-medium text-charcoal">{{ $subject->name }}</div>
                            <div class="text-xs text-charcoal/60">{{ $subject->code }}</div>
                        </div>
                        <x-status-pill :status="$subject->is_active ? 'ok' : 'red'"
                                       :label="$subject->is_active ? __('opes.subjects_screen.status_active') : __('opes.subjects_screen.status_inactive')"/>
                    </div>
                    <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                        <div class="flex justify-between gap-2">
                            <dt class="text-charcoal/60">{{ __('opes.subjects_screen.column_name_fr') }}</dt>
                            <dd>{{ $subject->name_fr ?? '—' }}</dd>
                        </div>
                        <div class="flex justify-between gap-2">
                            <dt class="text-charcoal/60">{{ __('opes.subjects_screen.column_department') }}</dt>
                            <dd>{{ $subject->department_id !== null ? ($departmentNames[$subject->department_id] ?? '—') : __('opes.subjects_screen.no_department') }}</dd>
                        </div>
                    </dl>
                    @if ($canManage)
                        <div class="mt-2 border-t border-border-primary pt-2">
                            <button type="button" wire:click="startEdit({{ $subject->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                {{ __('opes.subjects_screen.edit') }}
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </x-slot:cards>
    </x-list-screen>
</div>
