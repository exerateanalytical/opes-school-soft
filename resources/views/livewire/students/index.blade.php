@php
    use App\Modules\Students\Domain\StudentStatus;

    /**
     * Status -> pill tone. One central mapping so a status never reads green on
     * one screen and red on another. x-status-pill carries only three tones
     * (09-ui 10 - colour is never the only signal, the WORD carries meaning),
     * so several statuses share a tone and are told apart by their label.
     */
    $statusTone = [
        StudentStatus::Active->value => 'ok',
        StudentStatus::Graduated->value => 'ok',
        StudentStatus::Prospective->value => 'amber',
        StudentStatus::Inactive->value => 'amber',
        StudentStatus::TransferredOut->value => 'red',
        StudentStatus::Withdrawn->value => 'red',
        StudentStatus::Deceased->value => 'red',
    ];

    $initials = static function (string $first, string $last): string {
        return mb_strtoupper(mb_substr($first, 0, 1).mb_substr($last, 0, 1));
    };

    // The five tabs of 11.1, in the mockup's order. Counts are the same
    // dataset-wide numbers the KPI cards read, so a tab can never disagree
    // with the tile above it.
    $tabs = [
        ['value' => '', 'label' => __('opes.students_screen.tab_all'), 'count' => $totalStudents],
        ['value' => StudentStatus::Active->value, 'label' => __('opes.students_screen.status_active'), 'count' => $statusCounts[StudentStatus::Active->value] ?? 0],
        ['value' => StudentStatus::Inactive->value, 'label' => __('opes.students_screen.status_inactive'), 'count' => $statusCounts[StudentStatus::Inactive->value] ?? 0],
        ['value' => StudentStatus::Graduated->value, 'label' => __('opes.students_screen.status_graduated'), 'count' => $statusCounts[StudentStatus::Graduated->value] ?? 0],
        ['value' => StudentStatus::TransferredOut->value, 'label' => __('opes.students_screen.status_transferred_out'), 'count' => $statusCounts[StudentStatus::TransferredOut->value] ?? 0],
    ];

    $railTotal = array_sum(array_column($classGroupOptions, 'students'));
@endphp

{{-- Sidebar quick actions. 11.1 lists seven; only "Add New Student" has a
     screen in Phase 2 - and even that one belongs to a sibling workstream, so
     it is rendered inert here rather than linked at a route that may not
     exist. The other six mirror the shell's convention for unbuilt features:
     present, aria-disabled, and carrying the standard "arrives later" title. --}}
@push('sidebar-quick-actions')
    <div class="mx-3 mt-auto rounded-lg border border-heritage-yellow/70 p-3">
        <h2 class="text-xs font-bold uppercase tracking-wide text-heritage-yellow">
            {{ __('opes.dashboard.quick_actions') }}
        </h2>
        <ul class="mt-2 space-y-1">
            @foreach ([
                __('opes.students_screen.add_student'),
                'Bulk Import Students',
                'Generate Student ID Cards',
                'Print Student List',
                'Student Promotion',
                'Transfer Students',
                'Export Student Data',
            ] as $unbuilt)
                <li>
                    <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                          class="flex cursor-not-allowed items-center gap-2 rounded px-2 py-1.5 text-sm text-white/40">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-white/30" aria-hidden="true"></span>
                        {{ $unbuilt }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endpush

<x-list-screen
    :title="__('opes.students_screen.title')"
    :breadcrumb="[__('opes.students_screen.breadcrumb_dashboard'), __('opes.students_screen.breadcrumb_students')]"
    :paginator="$students"
    :empty-message="__('opes.students_screen.empty')"
    :rail-title="__('opes.students_screen.rail_title')"
>
    <x-slot:actions>
        {{-- Creation is a sibling workstream's screen and has no route in
             routes/web.php, so this is an inert control that says so rather
             than a link to a 404. --}}
        <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
              class="cursor-not-allowed rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/40">
            {{ __('opes.students_screen.add_student') }}
        </span>
    </x-slot:actions>

    {{-- KPI row. Every tile below is a REAL dataset-wide count taken from two
         grouped queries in the component - nothing here is a page-local
         figure and nothing is invented.

         Deliberately NOT rendered, because the data does not exist in Phase 2:
           * "New Admissions (this term)" - needs the assessment-period
             calendar to say which term "this" is; enrollments carry a year,
             not a term.
           * The "+6.45% from last term" trend line under every mockup tile -
             11.1 asks for a PERSISTED DAILY COUNT and no snapshot table
             exists, so there is no yesterday to compare against. A trend
             computed from the only number we have would be a fabrication.
         "Graduated" is therefore all-time, not this-academic-year, and its
         label says "Graduated" rather than the mockup's year-scoped wording. --}}
    <x-slot:kpis>
        <x-kpi-card :label="__('opes.students_screen.kpi_total')" :value="$totalStudents" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3.2"/><path stroke-linecap="round" d="M2.8 19.5c0-3.4 2.8-6.2 6.2-6.2s6.2 2.8 6.2 6.2"/><path stroke-linecap="round" d="M15.5 8.3a2.8 2.8 0 110 5.6M20.5 19.5c0-2.6-1.9-4.8-4.4-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.students_screen.kpi_active')"
                    :value="$statusCounts[StudentStatus::Active->value] ?? 0" icon-bg="bg-badge-teal">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 12.5l5 5 11-11"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.students_screen.kpi_male')" :value="$maleCount" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="14" r="5"/><path stroke-linecap="round" d="M14 10l6-6M15 4h5v5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.students_screen.kpi_female')" :value="$femaleCount" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="9" r="5"/><path stroke-linecap="round" d="M12 14v7M9 18h6"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.students_screen.kpi_graduated')"
                    :value="$statusCounts[StudentStatus::Graduated->value] ?? 0" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 4l9 4.5-9 4.5-9-4.5L12 4z"/><path stroke-linecap="round" d="M6 10.5V16c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="students-filter-search" class="flex min-w-[14rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.students_screen.search_label') }}</span>
            <input id="students-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="{{ __('opes.students_screen.search_placeholder') }}"
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>

        {{-- Class groups of the CURRENT academic year only. A list carrying
             every year's groups would offer filters that can never match a
             currently-enrolled student. --}}
        <label for="students-filter-class" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.students_screen.class_label') }}</span>
            <select id="students-filter-class" wire:model.live="classGroup"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.students_screen.all_classes') }}</option>
                @foreach ($classGroupOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        <label for="students-filter-status" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">{{ __('opes.students_screen.status_label') }}</span>
            <select id="students-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">{{ __('opes.ui.all') }}</option>
                @foreach ($statusOptions as $statusOption)
                    <option value="{{ $statusOption->value }}">{{ __('opes.students_screen.status_'.$statusOption->value) }}</option>
                @endforeach
            </select>
        </label>

        {{-- The mockup also filters by Gender and Admission Year. Gender is a
             real column and Admission Year is not (first_admission_date is a
             date, and "admission year" in the mockup means the ACADEMIC year
             of the first enrollment). Rather than ship one of a pair and leave
             the other looking broken, both are left to the follow-up that adds
             the academic-year join - the four filters here all work. --}}
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tab)
            <button type="button" wire:click="selectStatus('{{ $tab['value'] }}')"
                    @if ($status === $tab['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $status === $tab['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tab['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tab['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_matricule') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_student') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_class') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.date_of_birth') }}</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_status') }}</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($students as $student)
        <tr wire:key="student-row-{{ $student->id }}">
            <td class="px-4 py-2.5 font-mono text-xs text-charcoal/80">{{ $student->matricule }}</td>
            <td class="px-4 py-2.5">
                <div class="flex items-center gap-2.5">
                    {{-- Initials avatar, not the mockup's photo: photo_path is
                         a PRIVATE-disk path served through a policy-checked
                         controller (8.1) and that controller does not exist
                         yet, so there is nothing safe to point an <img> at. --}}
                    <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                        {{ $initials($student->first_name, $student->last_name) }}
                    </span>
                    <div class="min-w-0">
                        <div class="truncate font-medium text-charcoal">{{ $student->fullName() }}</div>
                        <div class="truncate text-xs text-charcoal/60">{{ __('opes.students_screen.admission_no') }} {{ $student->admission_no }}</div>
                    </div>
                </div>
            </td>
            <td class="px-4 py-2.5">
                @if (is_string($student->current_class_name) && $student->current_class_name !== '')
                    <span class="inline-flex items-center rounded-full border border-primary/30 bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary">
                        {{ $student->current_class_name }}
                    </span>
                @else
                    <span class="text-xs text-charcoal/50">{{ __('opes.students_screen.no_class') }}</span>
                @endif
            </td>
            <td class="px-4 py-2.5 text-charcoal/70">{{ $student->date_of_birth->translatedFormat('d M Y') }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$statusTone[$student->status->value] ?? 'amber'"
                                :label="__('opes.students_screen.status_'.$student->status->value)"/>
            </td>
            <td class="px-4 py-2.5">
                <div class="flex items-center justify-end gap-1">
                    <a href="{{ route('students.show', $student) }}"
                       title="{{ __('opes.students_screen.view_profile') }}"
                       class="rounded p-1.5 text-charcoal/50 hover:bg-sand hover:text-primary">
                        <span class="sr-only">{{ __('opes.students_screen.view_profile') }}</span>
                        <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    </a>
                </div>
            </td>
        </tr>
    @endforeach

    <x-slot:cards>
        @foreach ($students as $student)
            <article wire:key="student-card-{{ $student->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-start justify-between gap-2">
                    <div class="flex items-center gap-2.5">
                        <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                            {{ $initials($student->first_name, $student->last_name) }}
                        </span>
                        <div class="min-w-0">
                            <a href="{{ route('students.show', $student) }}" class="font-medium text-charcoal hover:text-primary">
                                {{ $student->fullName() }}
                            </a>
                            <div class="font-mono text-xs text-charcoal/60">{{ $student->matricule }}</div>
                        </div>
                    </div>
                    <x-status-pill :status="$statusTone[$student->status->value] ?? 'amber'"
                                    :label="__('opes.students_screen.status_'.$student->status->value)"/>
                </div>
                <dl class="mt-2 space-y-1 text-sm text-charcoal/80">
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.students_screen.column_class') }}</dt>
                        <dd>{{ is_string($student->current_class_name) && $student->current_class_name !== '' ? $student->current_class_name : __('opes.students_screen.no_class') }}</dd>
                    </div>
                    <div class="flex justify-between gap-2">
                        <dt class="text-charcoal/60">{{ __('opes.students_screen.admission_no') }}</dt>
                        <dd>{{ $student->admission_no }}</dd>
                    </div>
                </dl>
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: "Students by Class" (11.1). Real counts - one open
         EnrollmentSegment per live Enrollment, per class group of the current
         year. Rendered as proportional bars rather than the mockup's donut:
         a charting dependency is a decision the owner has not made, and the
         Users screen set the precedent of CSS-only chrome for the same reason. --}}
    <x-slot:rail>
        <div class="rounded border border-border-primary bg-white p-4">
            @if ($classGroupOptions === [])
                <p class="text-sm text-charcoal/60">{{ __('opes.ui.no_data') }}</p>
            @else
                <ul class="space-y-2">
                    @foreach ($classGroupOptions as $option)
                        <li>
                            <div class="flex items-baseline justify-between gap-2 text-xs">
                                <span class="truncate font-medium text-charcoal">{{ $option['name'] }}</span>
                                <span class="shrink-0 text-charcoal/60">{{ $option['students'] }}</span>
                            </div>
                            <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                <div class="h-1.5 rounded-full bg-primary"
                                     style="width: {{ $railTotal > 0 ? round(100 * $option['students'] / $railTotal) : 0 }}%"></div>
                            </div>
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </x-slot:rail>
</x-list-screen>
