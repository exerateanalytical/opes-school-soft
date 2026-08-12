{{--
    The child-scoped header and tab strip, built to results-overview.png and
    fees-dashboard.png: a back link, the child context card (photo, name,
    Active pill, class and matricule, Switch child), then the tab strip.

    Included - not a Blade component - because Laravel only auto-discovers
    `<x-...>` under resources/views/components, and this needs
    ['studentId' => ..., 'childName' => ..., 'active' => 'results'|...].

    A tab renders only when the guardian holds the capability its screen
    requires. This is PRESENTATION, not the gate - every screen re-authorizes
    on entry, and must, because a hidden link is not a control (00-core 6.2).
    But offering a tab that answers 403 the instant it is tapped is its own
    bug: the school closed that door deliberately, and the portal should show
    that decision rather than invite the parent to discover it as a wall.
--}}
@php
    $portalPolicy = app(\App\Modules\Guardians\Policies\GuardianPortalPolicy::class);
    $cap = \App\Modules\Guardians\Domain\GuardianCapability::class;

    // The capability each tab's screen authorizes on entry, kept beside the
    // tab list so the two cannot drift apart silently.
    $portalTabs = array_filter([
        'profile' => ['portal.children.profile', __('opes.guardian_portal.tab_profile'), 'user', $cap::R01ViewChildIdentity],
        'results' => ['portal.children.results', __('opes.guardian_portal.tab_results'), 'book', $cap::R05ViewReportCard],
        'attendance' => ['portal.children.attendance', __('opes.guardian_portal.tab_attendance'), 'calendar', $cap::R11ViewAttendanceSummary],
        'timetable' => ['portal.children.timetable', __('opes.guardian_portal.tab_timetable'), 'clock', $cap::R26ViewTimetableAndAnnouncements],
        'fees' => ['portal.children.fees', __('opes.guardian_portal.tab_fees'), 'card', $cap::R16ViewOwnPayments],
        'discipline' => ['portal.children.discipline', __('opes.guardian_portal.tab_discipline'), 'alert', $cap::R19ViewDisciplineList],
        'health' => ['portal.children.health', __('opes.guardian_portal.tab_health'), 'heart', $cap::R03ViewChildEmergencyMedical],
        'documents' => ['portal.children.documents', __('opes.guardian_portal.tab_documents'), 'file', $cap::R22ViewSchoolIssuedDocuments],
    ], fn (array $tab): bool => $portalPolicy->allows($tab[3], $studentId));

    $portalChildClass = \Illuminate\Support\Facades\DB::table('enrollment_segments as seg')
        ->join('enrollments as enr', 'enr.id', '=', 'seg.enrollment_id')
        ->join('class_groups as cg', 'cg.id', '=', 'seg.class_group_id')
        ->where('enr.student_id', $studentId)
        ->whereNull('seg.ends_on')
        ->orderByDesc('seg.starts_on')
        ->value('cg.name');

    $portalChildMatricule = \Illuminate\Support\Facades\DB::table('students')
        ->where('id', $studentId)->value('matricule');
@endphp

<div class="min-w-0 space-y-4 pt-2">
    <a href="{{ route('portal.dashboard') }}"
       class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
             stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
            <path d="M15 19l-7-7 7-7"/>
        </svg>
        {{ __('opes.guardian_portal.back_to_dashboard') }}
    </a>

    {{-- The child context card. Present on every child screen in the designs,
         and worth it: a parent with three children must never have to guess
         whose balance they are looking at. --}}
    <x-portal.card class="flex flex-wrap items-center gap-4">
        <x-portal.avatar :name="$childName" size="xl" tone="green"
                         :photo="route('portal.photo.child', $studentId)"/>

        <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2">
                <h1 class="truncate text-xl font-bold text-charcoal">{{ $childName }}</h1>
                <span class="inline-flex items-center gap-1.5 rounded-full bg-portal-chip px-2.5 py-0.5 text-xs font-semibold text-portal-success">
                    <span class="h-1.5 w-1.5 rounded-full bg-portal-success" aria-hidden="true"></span>
                    {{ __('opes.guardian_portal.status_active') }}
                </span>
            </div>

            <p class="mt-1 truncate text-sm text-charcoal/70">
                {{ collect([$portalChildClass, $portalChildMatricule])->filter()->join('  •  ') }}
            </p>
        </div>

        <a href="{{ route('portal.dashboard') }}"
           class="inline-flex shrink-0 items-center gap-2 rounded-xl border border-primary px-3 py-2 text-xs font-semibold text-primary hover:bg-portal-tint">
            <x-portal.icon name="users" bare size="sm"/>
            {{ __('opes.guardian_portal.switch_child') }}
        </a>
    </x-portal.card>

    {{-- Scrolls horizontally on a phone rather than wrapping: eight wrapped
         tabs push every child screen's content two rows down at 360px. --}}
    <nav aria-label="{{ __('opes.guardian_portal.tab_nav_label') }}"
         class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
        <div class="inline-flex gap-1 rounded-2xl border border-border-primary bg-white p-1.5 shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
            @foreach ($portalTabs as $key => [$routeName, $label, $icon, $capability])
                <a href="{{ route($routeName, $studentId) }}"
                   @if ($active === $key) aria-current="page" @endif
                   class="flex shrink-0 flex-col items-center gap-1 rounded-xl px-3.5 py-2 text-[11px] font-medium {{ $active === $key
                       ? 'bg-portal-green text-white'
                       : 'text-charcoal/60 hover:bg-portal-tint hover:text-primary' }}">
                    <x-portal.icon :name="$icon" bare size="sm"/>
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </nav>
</div>
