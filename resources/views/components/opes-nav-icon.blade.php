@props([
    'navKey' => '',
])

{{--
    Small icon-per-nav-item set for the sidebar (frontend images mockups show
    an icon beside every label). These are simple inline outline glyphs built
    from the same stroke conventions as the rest of the shell chrome - not an
    icon-font or SVG-sprite dependency, so no new build step is introduced.
    A key with no entry below falls back to a plain dot rather than nothing,
    so a future nav item never renders with a missing/broken icon.
--}}
@php
    $paths = [
        'dashboard' => '<rect x="3" y="3" width="8" height="8" rx="1.5"/><rect x="13" y="3" width="8" height="5" rx="1.5"/><rect x="13" y="10" width="8" height="11" rx="1.5"/><rect x="3" y="13" width="8" height="8" rx="1.5"/>',
        'admissions' => '<path d="M3 12l9-7 9 7"/><path d="M5 10v9a1 1 0 001 1h4v-6h4v6h4a1 1 0 001-1v-9"/>',
        'students' => '<circle cx="12" cy="8" r="4"/><path d="M4 21c0-4.4 3.6-8 8-8s8 3.6 8 8"/>',
        'guardians' => '<circle cx="8" cy="9" r="3"/><circle cx="17" cy="9" r="3"/><path d="M2 21c0-3.3 2.7-6 6-6s6 2.7 6 6M12 21c0-3.3 2.7-6 6-6"/>',
        'staff' => '<rect x="4" y="7" width="16" height="13" rx="1.5"/><path d="M9 7V5a2 2 0 012-2h2a2 2 0 012 2v2"/>',
        'payroll' => '<rect x="3" y="4" width="18" height="16" rx="2"/><path d="M7 9h10M7 13h6M15 17.5l1.5 1.5 2.5-3"/>',
        'assets' => '<rect x="4" y="4" width="16" height="16" rx="2"/><path d="M8 4v16M4 10h16"/>',
        'academics' => '<path d="M2 9l10-5 10 5-10 5-10-5z"/><path d="M6 11.5V17c0 1.7 2.7 3 6 3s6-1.3 6-3v-5.5"/><path d="M22 9v6.5"/>',
        'classes' => '<path d="M12 4l9 3.5-9 3.5-9-3.5L12 4z"/><path d="M4.5 9.5V15c0 1.5 3.5 3 7.5 3s7.5-1.5 7.5-3V9.5"/>',
        'subjects' => '<path d="M4 4h11a2 2 0 012 2v14H6a2 2 0 01-2-2V4z"/><path d="M4 4v14a2 2 0 002 2h13"/>',
        'timetable' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M3 9h18M8 2v4M16 2v4"/>',
        'attendance' => '<rect x="3" y="4" width="18" height="17" rx="2"/><path d="M8 13l2.5 2.5L16 10"/>',
        'examinations' => '<path d="M4 19V6a2 2 0 012-2h8l4 4v11a2 2 0 01-2 2H6a2 2 0 01-2-2z"/><path d="M8 12h8M8 16h5"/>',
        'results' => '<path d="M4 21V10M10 21V4M16 21v-7M22 21H2"/>',
        'finance' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v10M9.5 9.5c0-1.4 1.1-2.5 2.5-2.5s2.5 1 2.5 2.2-1 1.8-2.5 2-2.5 .8-2.5 2 1.1 2.3 2.5 2.3 2.5-1.1 2.5-2.5"/>',
        'finance_dashboard' => '<path d="M3 3v18h18"/><path d="M7 15l4-4 3 3 5-6"/><circle cx="7" cy="15" r="1.2"/><circle cx="19" cy="8" r="1.2"/>',
        'ledger' => '<path d="M6 3h9l5 5v13a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path d="M14 3v5h5"/><path d="M8 12h8M8 15.5h8M8 8.5h3"/>',
        'expenses' => '<path d="M4 6h16a1 1 0 011 1v10a1 1 0 01-1 1H4a1 1 0 01-1-1V7a1 1 0 011-1z"/><circle cx="12" cy="12" r="2.5"/><path d="M7 12h.01M17 12h.01"/>',
        'statements' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h5"/>',
        // Go-live readiness: a checklist clipboard.
        'setup' => '<path d="M9 3h6v3H9z"/><path d="M6 5h2m8 0h2a1 1 0 011 1v14a1 1 0 01-1 1H6a1 1 0 01-1-1V6a1 1 0 011-1z"/><path d="M9 12l2 2 4-4"/>',
        // Data import: an arrow landing into a tray.
        'import' => '<path d="M12 3v12"/><path d="M8 11l4 4 4-4"/><path d="M4 17v2a2 2 0 002 2h12a2 2 0 002-2v-2"/>',
        // Statutory books: a bound ledger.
        'books' => '<path d="M4 5a2 2 0 012-2h13v18H6a2 2 0 01-2-2z"/><path d="M9 3v18"/>',
        // Reconciliation: two arrows tying back to each other.
        'reconciliation' => '<path d="M4 8h12l-3-3M20 16H8l3 3"/>',
        // Budgets: a target, for planned-vs-actual.
        'budgets' => '<circle cx="12" cy="12" r="8"/><circle cx="12" cy="12" r="4"/><circle cx="12" cy="12" r="1"/>',
        'procurement' => '<path d="M3 6h2l1.6 9.6A2 2 0 008.6 17H18a2 2 0 002-1.6L21.5 8H6.2"/><circle cx="9.5" cy="20.5" r="1.5"/><circle cx="17.5" cy="20.5" r="1.5"/>',
        'tax' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M9 15l6-6M9.5 9.5h.01M14.5 14.5h.01"/>',
        'library' => '<path d="M4 19.5A2.5 2.5 0 016.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 014 19.5v-15A2.5 2.5 0 016.5 2z"/>',
        'inventory' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 7v10l9 4 9-4V7M12 11v10"/>',
        'transport' => '<rect x="3" y="8" width="18" height="9" rx="2"/><circle cx="7.5" cy="18" r="1.7"/><circle cx="16.5" cy="18" r="1.7"/><path d="M3 12h18M6 8V5h9l3 3"/>',
        'hostel' => '<path d="M4 10.5L12 4l8 6.5"/><path d="M6 10v10h12V10"/><path d="M10 20v-6h4v6"/>',
        'medical' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M12 8v8M8 12h8"/>',
        'visitors' => '<circle cx="12" cy="8" r="3.5"/><path d="M5 20c0-3.9 3.1-7 7-7s7 3.1 7 7"/><path d="M2 8l2.5 2.5L9 6"/>',
        'insurance' => '<path d="M12 3l8 3v6c0 5-3.5 8.5-8 9-4.5-.5-8-4-8-9V6l8-3z"/><path d="M9 12l2 2 4-4"/>',
        'reports' => '<rect x="3" y="3" width="18" height="18" rx="2"/><path d="M8 17V11M12 17V7M16 17v-5"/>',
        'operations' => '<path d="M21 12a9 9 0 01-15.4 6.4M3 12a9 9 0 0115.4-6.4"/><path d="M3 17v-4h4M21 7v4h-4"/>',
        'users' => '<circle cx="9" cy="8" r="3.5"/><path d="M2.5 20c0-3.6 2.9-6.5 6.5-6.5S15.5 16.4 15.5 20"/><path d="M16 8.5a3 3 0 110 6M21.5 20c0-2.8-2-5.1-4.7-5.9"/>',
        'audit_log' => '<path d="M4 4h11l5 5v11a1 1 0 01-1 1H4a1 1 0 01-1-1V5a1 1 0 011-1z"/><path d="M15 4v5h5"/><path d="M8 13l2.5 2.5L16 10"/>',
        'backups' => '<ellipse cx="12" cy="6" rx="8" ry="3"/><path d="M4 6v6c0 1.7 3.6 3 8 3s8-1.3 8-3V6"/><path d="M4 12v6c0 1.7 3.6 3 8 3s8-1.3 8-3v-6"/>',
        'bulk_prints' => '<path d="M7 8V4h10v4"/><rect x="4" y="8" width="16" height="7" rx="1.5"/><path d="M7 15h10v5H7z"/>',
        'outbox' => '<path d="M3 7l9 6 9-6"/><rect x="3" y="5" width="18" height="14" rx="2"/>',
        'message_templates' => '<rect x="4" y="3" width="16" height="18" rx="2"/><path d="M8 8h8M8 12h8M8 16h4"/>',
        'settings' => '<circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 11-2.83 2.83l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 11-4 0v-.09a1.65 1.65 0 00-1-1.51 1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 11-2.83-2.83l.06-.06a1.65 1.65 0 00.33-1.82 1.65 1.65 0 00-1.51-1H3a2 2 0 110-4h.09a1.65 1.65 0 001.51-1 1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 112.83-2.83l.06.06a1.65 1.65 0 001.82.33H9a1.65 1.65 0 001-1.51V3a2 2 0 114 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 112.83 2.83l-.06.06a1.65 1.65 0 00-.33 1.82V9a1.65 1.65 0 001.51 1H21a2 2 0 110 4h-.09a1.65 1.65 0 00-1.51 1z"/>',
    ];

    $inner = $paths[$navKey] ?? '<circle cx="12" cy="12" r="3"/>';
@endphp

<svg {{ $attributes->merge(['class' => '']) }} viewBox="0 0 24 24" fill="none" stroke="currentColor"
     stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
    {!! $inner !!}
</svg>
