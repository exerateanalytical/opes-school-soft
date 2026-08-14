@php
    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $statusTone = [
        'draft' => 'amber',
        'published' => 'ok',
    ];

    $statusLabel = [
        'draft' => 'Draft',
        'published' => 'Published',
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Inline "New Curriculum" panel. --}}
    @if ($showCreateForm)
        <section aria-label="New curriculum" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">New Curriculum</h2>
            <p class="mt-1 text-sm text-charcoal/60">
                Creates version 1 as a draft for one subject, class level, sub-system and academic year.
                A change after publication is a new version, never an edit in place.
            </p>

            <form wire:submit="saveCurriculum" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="curriculum-form-subject" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Subject</span>
                        <select id="curriculum-form-subject" wire:model="createFormSubjectId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a subject</option>
                            @foreach ($subjectOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('createFormSubjectId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="curriculum-form-level" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Class level</span>
                        <select id="curriculum-form-level" wire:model="createFormClassLevelId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a class level</option>
                            @foreach ($levelOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('createFormClassLevelId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="curriculum-form-year" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                        <select id="curriculum-form-year" wire:model="createFormAcademicYearId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">Select a year</option>
                            @foreach ($yearOptions as $option)
                                <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                            @endforeach
                        </select>
                        @error('createFormAcademicYearId')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="curriculum-form-subsystem" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Sub-system</span>
                        <select id="curriculum-form-subsystem" wire:model="createFormSubSystem"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($subSystemOptions as $option)
                                <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                            @endforeach
                        </select>
                        @error('createFormSubSystem')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="curriculum-form-title" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Title</span>
                        <input id="curriculum-form-title" type="text" wire:model="createFormTitle"
                               placeholder="e.g. Form 1 Mathematics Programme"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('createFormTitle')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="curriculum-form-description" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Description (optional)</span>
                        <textarea id="curriculum-form-description" wire:model="createFormDescription" rows="2"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('createFormDescription')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Create draft
                    </button>
                    <button type="button" wire:click="toggleCreateForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

<x-list-screen
    title="Curriculum Framework"
    :breadcrumb="['Dashboard', 'Curriculum']"
    :paginator="$rows"
    empty-message="No curricula match these filters yet. A curriculum is the versioned programme of study for one subject at one class level; create the first draft to begin."
>
    <x-slot:actions>
        @if ($canManage)
            <button type="button" wire:click="toggleCreateForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showCreateForm ? 'Hide form' : 'New curriculum' }}
            </button>
        @endif
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card label="Curricula" :value="$kpis['curricula']" tone="green">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 6.5c-1.6-1.2-3.7-1.7-6-1.5v13c2.3-.2 4.4.3 6 1.5 1.6-1.2 3.7-1.7 6-1.5v-13c-2.3-.2-4.4.3-6 1.5z"/><path stroke-linecap="round" d="M12 6.5v13"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Published" :value="$kpis['published']" tone="blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12.5l2 2 4-4.5"/><circle cx="12" cy="12" r="8.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Drafts" :value="$kpis['drafts']" tone="amber">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16.5 4.5l3 3L8 19l-4 1 1-4 11.5-11.5z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Competencies" :value="$kpis['competencies']" tone="purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.5 5 5.5.8-4 3.9.9 5.5-4.9-2.6-4.9 2.6.9-5.5-4-3.9 5.5-.8L12 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="curriculum-filter-subject" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Subject</span>
            <select id="curriculum-filter-subject" wire:model.live="subject"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All subjects</option>
                @foreach ($subjectOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="curriculum-filter-level" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Class level</span>
            <select id="curriculum-filter-level" wire:model.live="level"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All levels</option>
                @foreach ($levelOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="curriculum-filter-status" class="flex min-w-[8rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Status</span>
            <select id="curriculum-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All statuses</option>
                <option value="draft">Draft</option>
                <option value="published">Published</option>
            </select>
        </label>

        <label for="curriculum-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="curriculum-filter-search" type="search" wire:model.live.debounce.300ms="search"
                   placeholder="Title or subject"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Curriculum</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Subject</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Class level</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Sub-system</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Year</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Version</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Units</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Competencies</th>
            <th class="px-4 py-3 text-xs font-semibold uppercase tracking-wide">Status</th>
        </tr>
    </x-slot:head>

    <x-slot:cards>
        @foreach ($rows as $row)
            <article class="rounded-lg border border-border-primary bg-white p-3 shadow-sm">
                <a href="{{ route('curriculum.show', ['curriculum' => $row->id]) }}" class="font-semibold text-primary hover:underline">
                    {{ $row->title }}
                </a>
                <p class="mt-1 text-sm text-charcoal/70">
                    {{ $row->subject_name }} · {{ $row->level_name }} · {{ $row->year_code }}
                </p>
                <p class="mt-1 flex items-center gap-2 text-sm">
                    <span class="text-charcoal/60">v{{ $row->version }}</span>
                    <x-status-pill :status="$statusTone[$row->status] ?? 'ok'" :label="$statusLabel[$row->status] ?? $row->status"/>
                </p>
            </article>
        @endforeach
    </x-slot:cards>

    @foreach ($rows as $row)
        <tr wire:key="curriculum-{{ $row->id }}">
            <td class="px-4 py-3">
                <a href="{{ route('curriculum.show', ['curriculum' => $row->id]) }}" class="font-medium text-primary hover:underline">
                    {{ $row->title }}
                </a>
            </td>
            <td class="px-4 py-3 text-charcoal/80">{{ $row->subject_code }} - {{ $row->subject_name }}</td>
            <td class="px-4 py-3 text-charcoal/80">{{ $row->level_name }}</td>
            <td class="px-4 py-3 text-charcoal/80">{{ ucfirst($row->sub_system) }}</td>
            <td class="px-4 py-3 text-charcoal/80">{{ $row->year_code }}</td>
            <td class="px-4 py-3 text-charcoal/80">v{{ $row->version }}</td>
            <td class="px-4 py-3 text-charcoal/80">{{ $row->units_count }}</td>
            <td class="px-4 py-3 text-charcoal/80">{{ $row->competencies_count }}</td>
            <td class="px-4 py-3">
                <x-status-pill :status="$statusTone[$row->status] ?? 'ok'" :label="$statusLabel[$row->status] ?? $row->status"/>
            </td>
        </tr>
    @endforeach
</x-list-screen>
</div>
