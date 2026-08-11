{{--
    Shared child-scoped header + tab strip for the per-child portal screens,
    included (not a Blade component - Laravel only auto-discovers `<x-...>`
    components under resources/views/components) with
    ['studentId' => ..., 'childName' => ..., 'active' => 'results'|...].

    A tab is rendered only when the guardian actually holds the capability its
    screen requires. This is PRESENTATION, not the gate - every screen still
    re-authorizes on entry, and it must, because a hidden link is not a
    control (00-core 6.2).

    But offering a tab that answers 403 the moment it is clicked is its own
    kind of bug: the school configured this guardian's link deliberately, and
    the portal should reflect that decision rather than invite the parent to
    discover it as a wall. An earlier version of this strip rendered all eight
    unconditionally, and a non-custodial parent met a raw "403 FORBIDDEN" on
    two of them.
--}}
@php
    $portalPolicy = app(\App\Modules\Guardians\Policies\GuardianPortalPolicy::class);
    $cap = \App\Modules\Guardians\Domain\GuardianCapability::class;

    // The capability each tab's screen authorizes on entry. Kept beside the
    // tab list so the two cannot drift apart silently.
    $portalTabs = array_filter([
        'profile' => ['portal.children.profile', __('opes.guardian_portal.tab_profile'), $cap::R01ViewChildIdentity],
        'results' => ['portal.children.results', __('opes.guardian_portal.tab_results'), $cap::R05ViewReportCard],
        'attendance' => ['portal.children.attendance', __('opes.guardian_portal.tab_attendance'), $cap::R11ViewAttendanceSummary],
        'timetable' => ['portal.children.timetable', __('opes.guardian_portal.tab_timetable'), $cap::R26ViewTimetableAndAnnouncements],
        'fees' => ['portal.children.fees', __('opes.guardian_portal.tab_fees'), $cap::R16ViewOwnPayments],
        'discipline' => ['portal.children.discipline', __('opes.guardian_portal.tab_discipline'), $cap::R19ViewDisciplineList],
        'health' => ['portal.children.health', __('opes.guardian_portal.tab_health'), $cap::R03ViewChildEmergencyMedical],
        'documents' => ['portal.children.documents', __('opes.guardian_portal.tab_documents'), $cap::R22ViewSchoolIssuedDocuments],
    ], fn (array $tab): bool => $portalPolicy->allows($tab[2], $studentId));
@endphp
<div class="min-w-0">
    <a href="{{ route('portal.dashboard') }}" class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
        <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
        </svg>
        {{ __('opes.guardian_portal.back_to_dashboard') }}
    </a>

    <h1 class="mt-1 text-xl font-semibold text-charcoal">{{ $childName }}</h1>

    {{--
        Scrolls horizontally on a phone rather than wrapping. With eight tabs,
        wrapping pushes every child screen's content two rows down on a 360px
        viewport; scrolling keeps the page starting where a parent expects it.
    --}}
    <nav aria-label="{{ __('opes.guardian_portal.tab_nav_label') }}"
         class="mt-3 -mx-4 flex gap-1 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:flex-wrap sm:px-0">
        @foreach ($portalTabs as $key => [$routeName, $label, $capability])
            <a href="{{ route($routeName, $studentId) }}"
               @if ($active === $key) aria-current="page" @endif
               class="shrink-0 rounded-t border-b-2 px-3 py-2 text-sm font-medium {{ $active === $key
                   ? 'border-primary text-primary'
                   : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $label }}
            </a>
        @endforeach
    </nav>
</div>
