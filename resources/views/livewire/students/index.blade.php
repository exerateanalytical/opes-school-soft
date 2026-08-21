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

<x-list-screen
    :title="__('opes.students_screen.title')"
    :breadcrumb="[__('opes.students_screen.breadcrumb_dashboard'), __('opes.students_screen.breadcrumb_students')]"
    :paginator="$students"
    :empty-message="__('opes.students_screen.empty')"
>
    <x-slot:actions>
        {{-- These were ONE inert grey control, on the grounds that "creation
             has no route". It does: adding a student in this product IS the
             Student Admission Wizard at /admissions/wizard, which is routed,
             permissioned and built. A dead control beside a working screen
             reads as a broken build, so it now goes where the work actually
             happens.

             The reference's third button, "Export Students", is deliberately
             NOT here: no export route exists, and a button that does nothing
             is worse than an absent one. It goes in the ledger as feature
             work, not as a styled placeholder. --}}
        @can('students.manage')
            <a href="{{ route('students.import') }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg border border-shell-divider bg-white px-3.5 py-2 text-sm font-medium text-charcoal transition hover:border-primary hover:text-primary">
                <x-shell.icon name="arrow_right" class="h-4 w-4 -rotate-90"/>
                {{ __('opes.students_screen.import_students') }}
            </a>
        @endcan

        @can('admissions.manage')
            <a href="{{ route('admissions.wizard') }}" wire:navigate
               class="inline-flex items-center gap-2 rounded-lg bg-primary px-3.5 py-2 text-sm font-semibold text-white transition hover:bg-primary/90">
                <span aria-hidden="true" class="text-base leading-none">+</span>
                {{ __('opes.students_screen.add_student') }}
            </a>
        @else
            <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                  class="cursor-not-allowed rounded-lg border border-shell-divider px-3.5 py-2 text-sm font-medium text-charcoal/40">
                {{ __('opes.students_screen.add_student') }}
            </span>
        @endcan
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
        <x-kpi-card :label="__('opes.students_screen.kpi_total')" :value="$totalStudents"
                    :sub="__('opes.students_screen.kpi_total_sub')" icon-bg="bg-primary">
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

        {{-- "51.53% of total", as the reference shows - arithmetic on two
             figures already on this page, not a new claim. Guarded against a
             zero roll so an empty school shows the count and no percentage
             rather than a division by zero. --}}
        <x-kpi-card :label="__('opes.students_screen.kpi_male')" :value="$maleCount"
                    :sub="$totalStudents > 0
                        ? __('opes.students_screen.kpi_percent_of_total', ['percent' => number_format($maleCount / $totalStudents * 100, 2)])
                        : null"
                    icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="10" cy="14" r="5"/><path stroke-linecap="round" d="M14 10l6-6M15 4h5v5"/></svg>
            </x-slot:icon>
        </x-kpi-card>

        <x-kpi-card :label="__('opes.students_screen.kpi_female')" :value="$femaleCount"
                    :sub="$totalStudents > 0
                        ? __('opes.students_screen.kpi_percent_of_total', ['percent' => number_format($femaleCount / $totalStudents * 100, 2)])
                        : null"
                    icon-bg="bg-badge-purple">
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
            {{-- The reference opens with a select-all checkbox. It is NOT
                 reproduced: this screen has no bulk action to select FOR, and
                 a checkbox that selects rows nothing can act on is a dead
                 control - the same rule that keeps unbuilt modules out of the
                 nav. It goes back in with the first bulk operation. --}}
            <th scope="col" class="w-10 px-3 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_number') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_matricule') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_student') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_class') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_gender') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.date_of_birth') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_admission_date') }}</th>
            <th scope="col" class="px-2.5 py-2 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_status') }}</th>
            <th scope="col" class="px-2.5 py-2 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_actions') }}</th>
        </tr>
    </x-slot:head>

    @foreach ($students as $student)
        {{-- 13px, not the scaffold's 15px. This table carries eight
                 columns and the OPES sidebar is 270px against the reference
                 shell's 168px, so the content area is ~105px narrower for
                 the same table - which is exactly the width ACTIONS was
                 hanging off the right edge by. The reference's own table
                 body is set smaller than its page text for the same
                 reason. --}}
        <tr wire:key="student-row-{{ $student->id }}" class="text-[13px]">
            {{-- The row's position in the WHOLE result set, not in the page:
                 firstItem() is the paginator's own offset, so row 1 of page 2
                 reads 26 rather than restarting the count. --}}
            <td class="px-2.5 py-2 text-xs text-charcoal/55">{{ $students->firstItem() + $loop->index }}</td>
            <td class="px-2.5 py-2 font-mono text-xs text-charcoal/80">{{ $student->matricule }}</td>
            <td class="px-2.5 py-2">
                <div class="flex items-center gap-2.5">
                    {{-- Initials avatar, not the mockup's photo: photo_path is
                         a PRIVATE-disk path served through a policy-checked
                         controller (8.1) and that controller does not exist
                         yet, so there is nothing safe to point an <img> at. --}}
                    <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-chrome-light text-[10px] font-semibold uppercase text-white">
                        {{ $initials($student->first_name, $student->last_name) }}
                    </span>
                    {{-- Name only. The admission number used to sit under it
                         and is already its own column - printing an identifier
                         twice in one row costs ~140px of table width, which is
                         what pushed STATUS and ACTIONS off the right edge. --}}
                    <div class="min-w-0 truncate font-medium text-charcoal">{{ $student->fullName() }}</div>
                </div>
            </td>
            <td class="px-2.5 py-2">
                @if (is_string($student->current_class_name) && $student->current_class_name !== '')
                    <span class="inline-flex max-w-full items-center truncate whitespace-nowrap rounded-full border border-primary/30 bg-primary/10 px-2 py-0.5 text-xs font-semibold text-primary"
                          title="{{ $student->current_class_name }}">
                        {{ $student->current_class_name }}
                    </span>
                @else
                    <span class="text-xs text-charcoal/50">{{ __('opes.students_screen.no_class') }}</span>
                @endif
            </td>
            <td class="px-2.5 py-2">
                @if ($student->gender !== null)
                    <span class="inline-flex items-center gap-1.5 text-charcoal/80">
                        <span aria-hidden="true"
                              class="h-1.5 w-1.5 rounded-full {{ $student->gender->value === 'male' ? 'bg-kpi-blue-solid' : 'bg-kpi-pink-solid' }}"></span>
                        {{ __('opes.students_screen.gender_'.$student->gender->value) }}
                    </span>
                @else
                    <span class="text-xs text-charcoal/45">&mdash;</span>
                @endif
            </td>
            <td class="whitespace-nowrap px-2.5 py-2 text-charcoal/70">{{ $student->date_of_birth->translatedFormat('d M Y') }}</td>
            {{-- first_admission_date is nullable on a migrated record, and an
                 em dash is the honest reading of "we were never told when this
                 pupil first joined". --}}
            <td class="whitespace-nowrap px-2.5 py-2 text-charcoal/70">
                {{ $student->first_admission_date?->translatedFormat('d M Y') ?? '—' }}
            </td>
            <td class="px-2.5 py-2">
                <x-status-pill :status="$statusTone[$student->status->value] ?? 'amber'"
                                :label="__('opes.students_screen.status_'.$student->status->value)"/>
            </td>
            <td class="px-2.5 py-2">
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
                        <span class="flex h-7 w-7 shrink-0 items-center justify-center rounded-full bg-chrome-light text-[10px] font-semibold uppercase text-white">
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
        {{-- The reference's rail is two cards: a class donut, then a list of
             student operations. The distribution was a stack of progress
             bars; a donut reads the same data as SHARES, which is the
             question "how is the roll spread across classes" actually asks. --}}
        <x-shell.panel :title="__('opes.students_screen.rail_by_class')">
            <x-shell.donut :slices="$levelDistribution"
                           :centre-value="number_format($railTotal)"
                           :centre-label="__('opes.students_screen.kpi_total')"
                           stacked
                           :size="132"
                           :thickness="22"
                           class="py-1"/>
        </x-shell.panel>

        {{-- Every row is a route that EXISTS. The reference lists seven; the
             three it shows that this platform has no screen for - print a
             student list, transfer students, export student data - are
             absent rather than rendered as controls that go nowhere. They
             are recorded as feature work in the parity plan. --}}
        <x-shell.panel :title="__('opes.dashboard.quick_actions')" class="mt-4">
            <ul class="divide-y divide-shell-divider">
                @php
                    $railActions = [
                        ['key' => 'add_student', 'icon' => 'person_add', 'route' => 'admissions.wizard', 'can' => 'admissions.manage'],
                        ['key' => 'bulk_import', 'icon' => 'cloud_up', 'route' => 'students.import', 'can' => 'students.manage'],
                        ['key' => 'id_cards', 'icon' => 'boarding', 'route' => 'documents.bulk-prints', 'can' => 'documents.bulk_print'],
                        ['key' => 'promotion', 'icon' => 'academics', 'route' => 'students.promotion', 'can' => 'promotion.evaluate'],
                        ['key' => 'report', 'icon' => 'reports', 'route' => 'reports.students-guardians', 'can' => 'reports.view'],
                    ];
                @endphp

                @foreach ($railActions as $action)
                    @can($action['can'])
                        <a href="{{ route($action['route'], absolute: false) }}" wire:navigate
                           class="group flex h-[38px] items-center gap-2.5 text-[13px] text-charcoal transition hover:text-primary">
                            <x-shell.icon :name="$action['icon']" class="h-[17px] w-[17px] text-primary"/>
                            <span class="min-w-0 flex-1 truncate">{{ __('opes.students_screen.rail_'.$action['key']) }}</span>
                            <x-shell.icon name="chevron_right" class="h-3.5 w-3.5 text-charcoal/35"/>
                        </a>
                    @endcan
                @endforeach
            </ul>
        </x-shell.panel>
    </x-slot:rail>
</x-list-screen>
