{{-- Timetable Management — 09-ui §8.6, after the 'school timetable.png'
     mockup: filter bar, week grid (periods × Monday–Saturday) with BREAK rows
     full-width, class-details side panel, subject legend, Class / Teacher /
     Room / Exam tabs. Cell tints cycle a fixed pastel palette per subject
     (the mockup's colour-coding-by-category carve-out). --}}

@php
    $dayLabels = [
        1 => __('timetable.day.monday'),
        2 => __('timetable.day.tuesday'),
        3 => __('timetable.day.wednesday'),
        4 => __('timetable.day.thursday'),
        5 => __('timetable.day.friday'),
        6 => __('timetable.day.saturday'),
    ];
    $palette = ['#FDF3E3', '#E7F4EA', '#E8F0FB', '#F3E8FB', '#FBE8EE', '#EDF7E1', '#E4F5F3', '#FBF6DE'];
    $tint = fn (int $subjectId): string => $palette[$subjectId % count($palette)];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('timetable.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('timetable.breadcrumb_academics') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('timetable.breadcrumb_timetable') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('timetable.title') }}</h1>

        <div class="flex flex-wrap items-center gap-2">
            @if ($canManage)
                <button type="button" wire:click="startAssign"
                        class="flex items-center gap-1.5 rounded border border-chrome bg-chrome px-3 py-1.5 text-sm font-medium text-white hover:bg-chrome-light">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    {{ __('timetable.assign_subject') }}
                </button>
            @endif

            {{-- Present per the mockup; opens the "not available" notice —
                 auto-generation is out of v1 and silent no-ops are banned. --}}
            <button type="button" wire:click="generate"
                    class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('timetable.generate') }}
            </button>
        </div>
    </div>

    @if ($showGenerateNotice)
        <div class="flex items-start justify-between gap-3 rounded border border-heritage-yellow bg-heritage-yellow/10 px-3 py-2 text-sm text-charcoal"
             role="alert">
            <p>{{ __('timetable.generate_unavailable') }}</p>
            <button type="button" wire:click="dismissGenerateNotice" aria-label="{{ __('timetable.dismiss') }}"
                    class="font-semibold text-charcoal/60 hover:text-charcoal">&times;</button>
        </div>
    @endif

    @if ($currentYear === null)
        <x-empty-state :message="__('timetable.no_year')"/>
    @else
        {{-- ── Tabs: Class · Teacher · Room · Exam ────────────────────── --}}
        <div class="-mx-4 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:px-0">
            <div role="tablist" aria-label="{{ __('timetable.title') }}" class="flex min-w-max items-center gap-1">
                @foreach (\App\Modules\Academics\Livewire\Timetable\Index::TABS as $timetableTab)
                    <button type="button" role="tab" wire:click="selectTab('{{ $timetableTab }}')"
                            aria-selected="{{ $tab === $timetableTab ? 'true' : 'false' }}"
                            class="whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $timetableTab
                                ? 'border-primary font-semibold text-primary'
                                : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                        {{ __('timetable.tab_'.$timetableTab) }}
                    </button>
                @endforeach
            </div>
        </div>

        {{-- ── Filters ────────────────────────────────────────────────── --}}
        <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
            <div class="flex flex-wrap items-end gap-4">
                <div class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.year_label') }}</span>
                    <span class="rounded border border-border-primary bg-ivory px-3 py-1.5 text-sm text-charcoal">{{ $currentYear->code }}</span>
                </div>

                @if ($tab === 'class')
                    <label for="timetable-class" class="flex min-w-[12rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.class_label') }}</span>
                        <select id="timetable-class" wire:model.live="classGroupId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($classGroups as $group)
                                <option value="{{ $group->id }}">{{ $group->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @elseif ($tab === 'teacher')
                    <label for="timetable-teacher" class="flex min-w-[12rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.teacher_label') }}</span>
                        <select id="timetable-teacher" wire:model.live="staffMemberId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('timetable.teacher_placeholder') }}</option>
                            @foreach ($teacherNames as $staffId => $staffName)
                                <option value="{{ $staffId }}">{{ $staffName }}</option>
                            @endforeach
                        </select>
                    </label>
                @elseif ($tab === 'room')
                    <label for="timetable-room" class="flex min-w-[12rem] flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.room_label') }}</span>
                        <select id="timetable-room" wire:model.live="roomId"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="">{{ __('timetable.room_placeholder') }}</option>
                            @foreach ($roomOptions as $room)
                                <option value="{{ $room->id }}">{{ $room->name }}</option>
                            @endforeach
                        </select>
                    </label>
                @endif
            </div>
        </section>

        {{-- ── Assign panel ───────────────────────────────────────────── --}}
        @if ($showAssignForm && $canManage && $tab === 'class')
            <section aria-label="{{ __('timetable.assign_subject') }}"
                     class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
                <h2 class="text-base font-semibold text-charcoal">{{ __('timetable.assign_subject') }}</h2>

                @error('assign')
                    <p class="mt-2 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red" role="alert">
                        {{ $message }}
                    </p>
                @enderror

                <form wire:submit="assign" class="mt-4 space-y-4">
                    <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2 lg:grid-cols-3">
                        <label for="assign-day" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.field_day') }}</span>
                            <select id="assign-day" wire:model="assignDay"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">—</option>
                                @foreach ($dayLabels as $dayNumber => $dayLabel)
                                    <option value="{{ $dayNumber }}">{{ $dayLabel }}</option>
                                @endforeach
                            </select>
                            @error('assignDay')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="assign-period" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.field_period') }}</span>
                            <select id="assign-period" wire:model="assignPeriodId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">—</option>
                                @foreach ($periods->where('is_break', false) as $period)
                                    <option value="{{ $period->id }}">{{ $period->name }} ({{ substr($period->starts_at, 0, 5) }}–{{ substr($period->ends_at, 0, 5) }})</option>
                                @endforeach
                            </select>
                            @error('assignPeriodId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                            @error('timetable_period_id')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="assign-subject" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.field_subject') }}</span>
                            <select id="assign-subject" wire:model="assignSubjectId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">—</option>
                                @foreach ($subjectOptions as $subject)
                                    <option value="{{ $subject->id }}">{{ $subject->name }}</option>
                                @endforeach
                            </select>
                            @error('assignSubjectId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="assign-teacher" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.field_teacher') }}</span>
                            <select id="assign-teacher" wire:model="assignStaffId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">—</option>
                                @foreach ($teacherNames as $staffId => $staffName)
                                    <option value="{{ $staffId }}">{{ $staffName }}</option>
                                @endforeach
                            </select>
                            @error('assignStaffId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                            @error('staff_member_id')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>

                        <label for="assign-room" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">{{ __('timetable.field_room') }}</span>
                            <select id="assign-room" wire:model="assignRoomId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                                <option value="">{{ __('timetable.room_none') }}</option>
                                @foreach ($roomOptions as $room)
                                    <option value="{{ $room->id }}">{{ $room->name }}</option>
                                @endforeach
                            </select>
                            @error('assignRoomId')<span class="text-xs text-heritage-red">{{ $message }}</span>@enderror
                        </label>
                    </div>

                    <div class="flex items-center gap-2 border-t border-border-primary pt-4">
                        <button type="submit"
                                class="rounded border border-primary bg-primary px-4 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            {{ __('timetable.save') }}
                        </button>
                        <button type="button" wire:click="cancelAssign"
                                class="rounded border border-border-primary px-4 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('timetable.cancel') }}
                        </button>
                    </div>
                </form>
            </section>
        @endif

        {{-- ── Exam tab: Phase 3 sittings ─────────────────────────────── --}}
        @if ($tab === 'exam')
            <section class="rounded-lg border border-border-primary bg-white shadow-sm">
                <h2 class="border-b border-border-primary px-4 py-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('timetable.exam_heading') }}
                </h2>

                @if ($examSittings === [])
                    <p class="px-4 py-6 text-sm text-charcoal/60">{{ __('timetable.exam_empty') }}</p>
                @else
                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] border-collapse text-sm">
                            <thead>
                                <tr class="bg-chrome text-left text-white">
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_date') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_time') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_class') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_subject') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_room') }}</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.exam_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary">
                                @foreach ($examSittings as $sitting)
                                    <tr wire:key="exam-{{ $sitting->id }}">
                                        <td class="px-4 py-2.5 text-charcoal">{{ $sitting->scheduled_on }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ substr((string) $sitting->starts_at, 0, 5) }} · {{ $sitting->duration_minutes }} min</td>
                                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ $sitting->class_group_name }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $sitting->subject_name }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $sitting->room_name ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $sitting->status }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </section>
        @elseif (($tab === 'teacher' && $staffMemberId === '') || ($tab === 'room' && $roomId === ''))
            <x-empty-state :message="$tab === 'teacher' ? __('timetable.pick_teacher') : __('timetable.pick_room')"/>
        @elseif ($tab === 'class' && $classGroup === null)
            <x-empty-state :message="__('timetable.no_classes')"/>
        @elseif ($periods->isEmpty())
            <x-empty-state :message="__('timetable.no_periods')"/>
        @else
            <div class="grid grid-cols-1 gap-4 xl:grid-cols-4">
                {{-- ── The week grid ──────────────────────────────────── --}}
                <section class="rounded-lg border border-border-primary bg-white shadow-sm xl:col-span-3">
                    <h2 class="flex items-center justify-between border-b border-border-primary px-4 py-3 text-sm font-semibold text-charcoal">
                        <span>
                            {{ __('timetable.grid_heading') }}
                            @if ($tab === 'class' && $classGroup !== null) — {{ $classGroup->name }} @endif
                        </span>
                        <span class="text-xs font-normal text-charcoal/60">{{ __('timetable.year_label') }}: {{ $currentYear->code }}</span>
                    </h2>

                    <div class="overflow-x-auto">
                        <table class="w-full min-w-[40rem] border-collapse text-sm">
                            <thead>
                                <tr class="bg-chrome text-left text-white">
                                    <th scope="col" class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('timetable.column_time') }}</th>
                                    @foreach ($dayLabels as $dayLabel)
                                        <th scope="col" class="px-3 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ $dayLabel }}</th>
                                    @endforeach
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary">
                                @foreach ($periods as $period)
                                    <tr wire:key="period-row-{{ $period->id }}">
                                        @if ($period->is_break)
                                            <th scope="row" class="whitespace-nowrap bg-ivory px-3 py-2 text-left text-xs font-medium text-charcoal/70">
                                                {{ substr($period->starts_at, 0, 5) }} – {{ substr($period->ends_at, 0, 5) }}
                                            </th>
                                            <td colspan="6" class="bg-sand/60 px-3 py-2 text-center text-xs font-semibold uppercase tracking-widest text-charcoal/60">
                                                {{ $period->name }}
                                            </td>
                                        @else
                                            <th scope="row" class="whitespace-nowrap bg-ivory px-3 py-2 text-left text-xs text-charcoal/80">
                                                <span class="block">{{ substr($period->starts_at, 0, 5) }} – {{ substr($period->ends_at, 0, 5) }}</span>
                                                <span class="block font-semibold text-primary">{{ $period->name }}</span>
                                            </th>
                                            @foreach ($dayLabels as $dayNumber => $dayLabel)
                                                @php $cell = $cells[$period->id.'-'.$dayNumber] ?? null; @endphp
                                                <td class="px-1.5 py-1.5 align-top">
                                                    @if ($cell !== null)
                                                        <div class="group relative rounded px-2 py-1.5 text-xs"
                                                             style="background-color: {{ $tint($cell->subject_id) }}">
                                                            <span class="block font-semibold text-charcoal">{{ $cell->subject?->name ?? '—' }}</span>
                                                            <span class="block text-charcoal/70">{{ $teacherNames[$cell->staff_member_id] ?? '—' }}</span>
                                                            @if ($tab !== 'class')
                                                                <span class="block text-charcoal/60">{{ $cell->classGroup?->name }}</span>
                                                            @endif
                                                            @if ($cell->room !== null)
                                                                <span class="block text-charcoal/50">{{ $cell->room->name }}</span>
                                                            @endif
                                                            @if ($canManage && $tab === 'class')
                                                                <button type="button" wire:click="removeSlot({{ $cell->id }})"
                                                                        aria-label="{{ __('timetable.remove_slot') }}"
                                                                        class="absolute right-1 top-1 hidden rounded px-1 text-charcoal/40 hover:text-heritage-red group-hover:block">
                                                                    &times;
                                                                </button>
                                                            @endif
                                                        </div>
                                                    @else
                                                        <span class="block px-2 py-1.5 text-center text-xs text-charcoal/30">—</span>
                                                    @endif
                                                </td>
                                            @endforeach
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </section>

                {{-- ── Side panel: details + legend ─────────────────────── --}}
                <div class="space-y-4">
                    @if ($tab === 'class' && $classGroup !== null)
                        <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('timetable.details_heading') }}</h2>
                            <dl class="mt-3 space-y-2 text-sm">
                                <div class="flex justify-between gap-2">
                                    <dt class="text-charcoal/60">{{ __('timetable.details_class') }}</dt>
                                    <dd class="font-medium text-charcoal">{{ $classGroup->name }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-charcoal/60">{{ __('timetable.details_students') }}</dt>
                                    <dd class="font-medium text-charcoal">{{ $rosterCount ?? '—' }}</dd>
                                </div>
                                <div class="flex justify-between gap-2">
                                    <dt class="text-charcoal/60">{{ __('timetable.details_mode') }}</dt>
                                    <dd class="font-medium text-charcoal">{{ $classGroup->attendance_mode->label(app()->getLocale()) }}</dd>
                                </div>
                            </dl>
                        </section>
                    @endif

                    <section class="rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                            {{ __('timetable.legend_heading') }} ({{ $gridSlots->unique('subject_id')->count() }})
                        </h2>
                        <ul class="mt-3 space-y-1.5 text-sm">
                            @forelse ($gridSlots->unique('subject_id')->sortBy(fn ($slot) => $slot->subject?->name) as $slot)
                                <li class="flex items-center gap-2" wire:key="legend-{{ $slot->subject_id }}">
                                    <span aria-hidden="true" class="inline-block h-3 w-3 rounded-full border border-border-primary"
                                          style="background-color: {{ $tint($slot->subject_id) }}"></span>
                                    <span class="text-charcoal/80">{{ $slot->subject?->name ?? '—' }}</span>
                                </li>
                            @empty
                                <li class="text-charcoal/50">{{ __('timetable.legend_empty') }}</li>
                            @endforelse
                        </ul>
                    </section>
                </div>
            </div>
        @endif
    @endif
</div>
