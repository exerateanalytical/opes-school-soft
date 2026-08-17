{{-- Classes for the current academic year, composing x-list-screen. Without a
     current year the screen explains what to do (class groups are per-year,
     so there is nothing coherent to list) instead of rendering a bare table
     or crashing. --}}

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @if ($currentYear === null)
        <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
            <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
                <li>{{ __('opes.classes_screen.breadcrumb_dashboard') }}</li>
                <li aria-hidden="true" class="text-charcoal/30">/</li>
                <li aria-current="page" class="font-medium text-charcoal/80">{{ __('opes.classes_screen.breadcrumb_classes') }}</li>
            </ol>
        </nav>

        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.classes_screen.title') }}</h1>

        <x-empty-state :message="__('opes.classes_screen.no_year')">
            <x-slot:action>
                @can('academics.manage')
                    <a href="{{ route('academics.settings') }}"
                       class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.classes_screen.go_to_settings') }}
                    </a>
                @endcan
            </x-slot:action>
        </x-empty-state>
    @else
        {{-- Inline create panel (only `classes.index` is routed; creation
             happens on the list screen itself). --}}
        @if ($showForm && $canManage)
            <section aria-label="{{ $editingGroupId === null ? __('opes.classes_screen.form_title') : __('opes.classes_screen.form_edit_title') }}"
                     class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-charcoal">
                    {{ $editingGroupId === null ? __('opes.classes_screen.form_title') : __('opes.classes_screen.form_edit_title') }}
                </h2>

                <form wire:submit="save" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                        <label for="class-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.name_field') }}</span>
                            <input id="class-name" type="text" wire:model="className"
                                   placeholder="{{ __('opes.classes_screen.name_placeholder') }}"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('className')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                            {{-- CreateClassGroup reports the duplicate-name
                                 UNIQUE violation under `name`. --}}
                            @error('name')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="class-level" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_field') }}</span>
                            <select id="class-level" wire:model="classLevelId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">{{ __('opes.classes_screen.level_placeholder') }}</option>
                                @foreach ($levelOptions as $level)
                                    <option value="{{ $level->id }}">{{ app()->getLocale() === 'fr' ? $level->name_fr : $level->name }}</option>
                                @endforeach
                            </select>
                            @error('classLevelId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="class-stream" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.stream_field') }}</span>
                            <select id="class-stream" wire:model="streamId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">{{ __('opes.classes_screen.stream_placeholder') }}</option>
                                @foreach ($streamOptions as $stream)
                                    <option value="{{ $stream->id }}">{{ app()->getLocale() === 'fr' ? $stream->name_fr : $stream->name }}</option>
                                @endforeach
                            </select>
                            @error('streamId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="class-capacity" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.capacity_field') }}</span>
                            <input id="class-capacity" type="number" min="1" max="500" wire:model="capacity"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('capacity')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        @if ($editingGroupId !== null)
                            <label for="class-status" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.column_status') }}</span>
                                <select id="class-status" wire:model="groupStatus"
                                        class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                    <option value="active">{{ __('opes.classes_screen.status_active') }}</option>
                                    <option value="inactive">{{ __('opes.classes_screen.status_inactive') }}</option>
                                </select>
                                @error('groupStatus')
                                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif
                    </div>

                    <div class="flex items-center gap-2 border-t border-border-primary pt-4">
                        <button type="submit"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.classes_screen.save') }}
                        </button>
                        <button type="button" wire:click="cancelForm"
                                class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.classes_screen.cancel') }}
                        </button>
                    </div>
                </form>
            </section>
        @endif

        <x-list-screen
            :title="__('opes.classes_screen.title').' — '.$currentYear->code"
            :breadcrumb="[__('opes.classes_screen.breadcrumb_dashboard'), __('opes.classes_screen.breadcrumb_classes')]"
            :paginator="$classGroups"
            :empty-message="__('opes.classes_screen.empty')"
        >
            <x-slot:actions>
                @if ($canManage)
                    <button type="button" wire:click="startCreate"
                            class="flex items-center gap-1.5 rounded border border-chrome bg-chrome px-3 py-1.5 text-sm font-medium text-white hover:bg-chrome-light">
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                            <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                        </svg>
                        {{ __('opes.classes_screen.add_class') }}
                    </button>
                @endif
            </x-slot:actions>

            {{-- One real KPI: the paginator's dataset-wide total for the
                 current year. Enrolment/teacher tiles arrive with their
                 modules. --}}
            <x-slot:kpis>
                <x-kpi-card :label="__('opes.classes_screen.kpi_total')" :value="$classGroups->total()"
                            icon-bg="bg-primary" class="col-span-2 sm:col-span-1">
                    <x-slot:icon>
                        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
                            <rect x="3" y="4" width="18" height="14" rx="2"/>
                            <path stroke-linecap="round" d="M8 21h8M12 18v3M7 9h6M7 13h4"/>
                        </svg>
                    </x-slot:icon>
                </x-kpi-card>
            </x-slot:kpis>

            <x-slot:filters>
                <label for="classes-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.search_label') }}</span>
                    <input id="classes-filter-search" type="search" wire:model.live.debounce.400ms="search"
                           placeholder="{{ __('opes.classes_screen.search_placeholder') }}"
                           class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                </label>
            </x-slot:filters>

            <x-slot:head>
                <tr class="bg-chrome text-white">
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.classes_screen.column_name') }}</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.classes_screen.column_level') }}</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.classes_screen.column_stream') }}</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.classes_screen.column_capacity') }}</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.classes_screen.column_status') }}</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.subjects_screen.column_actions') }}</th>
                    @endif
                </tr>
            </x-slot:head>

            @foreach ($classGroups as $classGroup)
                <tr wire:key="class-group-{{ $classGroup->id }}">
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ $classGroup->name }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">
                        {{ $classGroup->classLevel !== null ? (app()->getLocale() === 'fr' ? $classGroup->classLevel->name_fr : $classGroup->classLevel->name) : '—' }}
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">
                        {{ $classGroup->stream !== null ? (app()->getLocale() === 'fr' ? $classGroup->stream->name_fr : $classGroup->stream->name) : __('opes.classes_screen.no_stream') }}
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $classGroup->capacity }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$classGroup->status === 'active' ? 'ok' : 'red'"
                                       :label="$classGroup->status === 'active' ? __('opes.classes_screen.status_active') : __('opes.classes_screen.status_inactive')"/>
                    </td>
                    @if ($canManage)
                        <td class="px-4 py-2.5">
                            <div class="flex items-center justify-end">
                                <button type="button" wire:click="startEditGroup({{ $classGroup->id }})"
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
            @endforeach

            <x-slot:cards>
                @foreach ($classGroups as $classGroup)
                    <article wire:key="class-group-card-{{ $classGroup->id }}" class="rounded border border-border-primary bg-white p-3">
                        <div class="flex items-start justify-between gap-2">
                            <div class="font-medium text-charcoal">{{ $classGroup->name }}</div>
                            <x-status-pill :status="$classGroup->status === 'active' ? 'ok' : 'red'"
                                           :label="$classGroup->status === 'active' ? __('opes.classes_screen.status_active') : __('opes.classes_screen.status_inactive')"/>
                        </div>
                        <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                            <div class="flex justify-between gap-2">
                                <dt class="text-charcoal/60">{{ __('opes.classes_screen.column_level') }}</dt>
                                <dd>{{ $classGroup->classLevel !== null ? (app()->getLocale() === 'fr' ? $classGroup->classLevel->name_fr : $classGroup->classLevel->name) : '—' }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-charcoal/60">{{ __('opes.classes_screen.column_stream') }}</dt>
                                <dd>{{ $classGroup->stream !== null ? (app()->getLocale() === 'fr' ? $classGroup->stream->name_fr : $classGroup->stream->name) : __('opes.classes_screen.no_stream') }}</dd>
                            </div>
                            <div class="flex justify-between gap-2">
                                <dt class="text-charcoal/60">{{ __('opes.classes_screen.column_capacity') }}</dt>
                                <dd>{{ $classGroup->capacity }}</dd>
                            </div>
                        </dl>
                        @if ($canManage)
                            <div class="mt-2 border-t border-border-primary pt-2">
                                <button type="button" wire:click="startEditGroup({{ $classGroup->id }})"
                                        class="text-sm font-medium text-primary hover:underline">
                                    {{ __('opes.subjects_screen.edit') }}
                                </button>
                            </div>
                        @endif
                    </article>
                @endforeach
            </x-slot:cards>
        </x-list-screen>
    @endif

    {{-- ── Levels & streams ────────────────────────────────────────────────
         Class levels and streams are year-independent, so this section sits
         OUTSIDE the current-year branch: a school setting itself up has to be
         able to define them before any year is current. The component has
         always carried startCreateLevel/startEditLevel/saveLevel and
         toggleStreamForm/saveStream — until now nothing rendered a control
         that reached them, so CreateClassLevel/UpdateClassLevel/CreateStream
         were unreachable from the UI. --}}
    @if ($canManage)
        <section aria-label="{{ __('opes.classes_screen.structure_heading') }}"
                 class="rounded-lg border border-border-primary bg-white shadow-sm">
            <div class="flex flex-wrap items-center justify-between gap-2 border-b border-border-primary px-4 py-3">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.classes_screen.structure_heading') }}
                </h2>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="button" wire:click="startCreateLevel"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.classes_screen.add_level') }}
                    </button>
                    <button type="button" wire:click="toggleStreamForm"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.classes_screen.add_stream') }}
                    </button>
                </div>
            </div>

            {{-- Class-level create/edit panel --}}
            @if ($showLevelForm)
                <form wire:submit="saveLevel" class="space-y-4 border-b border-border-primary p-4">
                    <h3 class="text-sm font-semibold text-charcoal">
                        {{ $editingLevelId === null ? __('opes.classes_screen.form_level_title') : __('opes.classes_screen.form_level_edit_title') }}
                    </h3>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label for="level-section" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_section_field') }}</span>
                            <select id="level-section" wire:model="levelSectionId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">{{ __('opes.classes_screen.level_section_placeholder') }}</option>
                                @foreach ($sectionOptions as $section)
                                    <option value="{{ $section->id }}">{{ app()->getLocale() === 'fr' ? $section->name_fr : $section->name }}</option>
                                @endforeach
                            </select>
                            @error('levelSectionId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="level-code" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_code_field') }}</span>
                            <input id="level-code" type="text" wire:model="levelCode"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('levelCode')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="level-order" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_order_field') }}</span>
                            <input id="level-order" type="number" min="0" wire:model="levelOrderIndex"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('levelOrderIndex')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="level-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_name_field') }}</span>
                            <input id="level-name" type="text" wire:model="levelName"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('levelName')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="level-name-fr" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_name_fr_field') }}</span>
                            <input id="level-name-fr" type="text" wire:model="levelNameFr"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('levelNameFr')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="level-exam-class" class="flex items-center gap-2 sm:pt-5">
                            <input id="level-exam-class" type="checkbox" wire:model="levelIsExamClass"
                                   class="h-4 w-4 rounded border-border-primary text-primary focus:ring-primary"/>
                            <span class="text-sm text-charcoal">{{ __('opes.classes_screen.level_exam_class_field') }}</span>
                        </label>
                    </div>

                    <div class="flex items-center gap-2 border-t border-border-primary pt-4">
                        <button type="submit"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.classes_screen.save') }}
                        </button>
                        <button type="button" wire:click="cancelLevelForm"
                                class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.classes_screen.cancel') }}
                        </button>
                    </div>
                </form>
            @endif

            {{-- Stream create panel --}}
            @if ($showStreamForm)
                <form wire:submit="saveStream" class="space-y-4 border-b border-border-primary p-4">
                    <h3 class="text-sm font-semibold text-charcoal">{{ __('opes.classes_screen.form_stream_title') }}</h3>

                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label for="stream-section" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.level_section_field') }}</span>
                            <select id="stream-section" wire:model="streamSectionId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">{{ __('opes.classes_screen.level_section_placeholder') }}</option>
                                @foreach ($sectionOptions as $section)
                                    <option value="{{ $section->id }}">{{ app()->getLocale() === 'fr' ? $section->name_fr : $section->name }}</option>
                                @endforeach
                            </select>
                            @error('streamSectionId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="stream-code" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.stream_code_field') }}</span>
                            <input id="stream-code" type="text" wire:model="streamCode"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('streamCode')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="stream-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.stream_name_field') }}</span>
                            <input id="stream-name" type="text" wire:model="streamName"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('streamName')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="stream-name-fr" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.stream_name_fr_field') }}</span>
                            <input id="stream-name-fr" type="text" wire:model="streamNameFr"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('streamNameFr')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="stream-basket" class="flex flex-col gap-1 sm:col-span-2">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.classes_screen.stream_basket_field') }}</span>
                            <input id="stream-basket" type="text" wire:model="streamSubjectBasket"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            <span class="text-xs text-charcoal/50">{{ __('opes.classes_screen.stream_basket_help') }}</span>
                            @error('streamSubjectBasket')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <div class="flex items-center gap-2 border-t border-border-primary pt-4">
                        <button type="submit"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('opes.classes_screen.save') }}
                        </button>
                        <button type="button" wire:click="toggleStreamForm"
                                class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.classes_screen.cancel') }}
                        </button>
                    </div>
                </form>
            @endif

            <div class="grid grid-cols-1 gap-4 p-4 lg:grid-cols-2">
                <div class="min-w-0">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-charcoal/60">{{ __('opes.classes_screen.column_level') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse ($levelOptions as $level)
                            <li wire:key="level-{{ $level->id }}" class="flex items-center justify-between gap-2 rounded border border-border-primary px-3 py-1.5">
                                <span class="min-w-0 truncate text-charcoal">
                                    <span class="font-mono text-xs text-charcoal/60">{{ $level->code }}</span>
                                    {{ app()->getLocale() === 'fr' ? $level->name_fr : $level->name }}
                                </span>
                                <button type="button" wire:click="startEditLevel({{ $level->id }})"
                                        class="shrink-0 text-sm font-medium text-primary hover:underline">
                                    {{ __('opes.classes_screen.edit_level') }}
                                </button>
                            </li>
                        @empty
                            <li class="text-charcoal/50">{{ __('opes.classes_screen.levels_empty') }}</li>
                        @endforelse
                    </ul>
                </div>

                <div class="min-w-0">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-charcoal/60">{{ __('opes.classes_screen.column_stream') }}</h3>
                    <ul class="mt-2 space-y-1 text-sm">
                        @forelse ($streamOptions as $stream)
                            <li wire:key="stream-{{ $stream->id }}" class="rounded border border-border-primary px-3 py-1.5">
                                <span class="font-mono text-xs text-charcoal/60">{{ $stream->code }}</span>
                                <span class="text-charcoal">{{ app()->getLocale() === 'fr' ? $stream->name_fr : $stream->name }}</span>
                            </li>
                        @empty
                            <li class="text-charcoal/50">{{ __('opes.classes_screen.streams_empty') }}</li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </section>
    @endif
</div>
