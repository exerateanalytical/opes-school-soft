{{--
    Shared child-scoped header + tab strip for the per-child portal screens,
    included (not a Blade component - Laravel only auto-discovers `<x-...>`
    components under resources/views/components) with
    ['studentId' => ..., 'childName' => ..., 'active' => 'results'|...].
    Every tab link is rendered unconditionally here (the screen it points at
    re-authorizes on entry, per GuardianPortalPolicy); this strip is chrome,
    not a gate.
--}}
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
        @foreach ([
            'profile' => ['portal.children.profile', __('opes.guardian_portal.tab_profile')],
            'results' => ['portal.children.results', __('opes.guardian_portal.tab_results')],
            'attendance' => ['portal.children.attendance', __('opes.guardian_portal.tab_attendance')],
            'timetable' => ['portal.children.timetable', __('opes.guardian_portal.tab_timetable')],
            'fees' => ['portal.children.fees', __('opes.guardian_portal.tab_fees')],
            'discipline' => ['portal.children.discipline', __('opes.guardian_portal.tab_discipline')],
            'health' => ['portal.children.health', __('opes.guardian_portal.tab_health')],
            'documents' => ['portal.children.documents', __('opes.guardian_portal.tab_documents')],
        ] as $key => [$routeName, $label])
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
