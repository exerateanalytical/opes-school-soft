@props([
    'name' => '',
])

{{--
    THE SHELL ICON SET - solid glyphs, 24x24, `fill="currentColor"`.

    WHY A SECOND SET. `x-opes-nav-icon` next door is the platform's OUTLINE
    set (fill="none", stroke="currentColor"), and it stays exactly as it is -
    every screen already drawing with it keeps its icons. But the sidebar in
    `frontend images/super admin dashbaord.png` is unambiguously drawn with
    SOLID gold glyphs: cropped at 4x and inspected, every one of the eighteen
    is a filled shape with no visible stroke. An outline glyph tinted gold is
    a different picture, not a near-miss, so the shell gets its own set rather
    than the reference being quietly approximated with what was already here.

    The two sets never mix inside one control: chrome (sidebar, top bar) draws
    from here, screen content draws from x-opes-nav-icon.

    Rules for adding one:
      1. 24x24 viewBox, `fill="currentColor"`, no stroke.
      2. Traced from the reference at 4x with tools/design-parity/desktop/
         crop.php - never invented, never "close enough".
      3. Named for the DOMAIN CONCEPT, not the picture, so a redraw is not a
         rename across every call site.
      4. Optical weight matched to its neighbours: these read at 18px, and a
         glyph built from thin shapes disappears beside one built from thick.

    An unknown name renders a small disc rather than nothing, so a future
    group can never blow a hole in the sidebar.
--}}
@php
    $glyphs = [
        // ── Sidebar groups, in the reference's own order ──────────────────
        // Dashboard: a house. Solid body, door cut out of the base.
        'dashboard' => '<path d="M11.36 3.3a1 1 0 0 1 1.28 0l8.5 7.2a1 1 0 0 1 .36.77V20a1.2 1.2 0 0 1-1.2 1.2h-5.05v-5.05a2.25 2.25 0 0 0-4.5 0V21.2H5.7A1.2 1.2 0 0 1 4.5 20v-8.73a1 1 0 0 1 .36-.76z"/>',

        // School Network: a figure with outstretched arms inside a ring -
        // one person standing for a whole campus in the group of campuses.
        'school_network' => '<path d="M12 1.8A10.2 10.2 0 1 0 22.2 12 10.21 10.21 0 0 0 12 1.8m0 1.9a8.3 8.3 0 1 1-8.3 8.3A8.31 8.31 0 0 1 12 3.7"/><circle cx="12" cy="7.15" r="1.75"/><path d="M12 9.4a2.6 2.6 0 0 0-2.29 1.36l-2.6 1.11a.92.92 0 0 0 .72 1.7l1.87-.8v1.02l-1.65 3.3a.92.92 0 0 0 1.64.83l1.72-3.43h1.18l1.72 3.43a.92.92 0 0 0 1.64-.83l-1.65-3.3v-1.02l1.87.8a.92.92 0 0 0 .72-1.7l-2.6-1.11A2.6 2.6 0 0 0 12 9.4"/>',

        // Students: one figure forward, a second set back behind it.
        'students' => '<circle cx="9.4" cy="7.8" r="3.35"/><path d="M9.4 12.5c-3.4 0-6.15 2.15-6.15 5.3v.9a1 1 0 0 0 1 1h10.3a1 1 0 0 0 1-1v-.9c0-3.15-2.75-5.3-6.15-5.3"/><circle cx="16.75" cy="8.6" r="2.6"/><path d="M16.75 12.9a5.9 5.9 0 0 0-1.28.14 7.36 7.36 0 0 1 1.98 4.94h2.98a1 1 0 0 0 1-1v-.62c0-2.5-2.13-3.46-4.68-3.46"/>',

        // Staff: three figures - a team rather than a pair.
        'staff' => '<circle cx="12" cy="7.6" r="3"/><path d="M12 12.1c-3.1 0-5.6 1.95-5.6 4.8v1a1 1 0 0 0 1 1h9.2a1 1 0 0 0 1-1v-1c0-2.85-2.5-4.8-5.6-4.8"/><circle cx="5.05" cy="9.15" r="2.3"/><path d="M5.05 12.6c-1.9 0-3.55 1.1-3.55 3v1.2a1 1 0 0 0 1 1h2.3v-1c0-1.65.62-3.1 1.7-4.15a5.4 5.4 0 0 0-1.45-.05"/><circle cx="18.95" cy="9.15" r="2.3"/><path d="M18.95 12.6a5.4 5.4 0 0 0-1.45.05c1.08 1.05 1.7 2.5 1.7 4.15v1h2.3a1 1 0 0 0 1-1v-1.2c0-1.9-1.65-3-3.55-3"/>',

        // Academics: a mortarboard - the cap over its own tassel plate.
        'academics' => '<path d="M12.46 2.63a1.2 1.2 0 0 0-.92 0L1.7 6.9a.6.6 0 0 0 0 1.1l3.1 1.34 6.74-2.9a.75.75 0 1 1 .6 1.38l-5.4 2.32 4.8 2.07a1.2 1.2 0 0 0 .92 0l7.86-3.38v4.72a.94.94 0 0 0 1.88 0V6.9a.6.6 0 0 0-.36-.55z"/><path d="M6.35 11.66v3.9a1.6 1.6 0 0 0 .86 1.42A11.4 11.4 0 0 0 12 18.3a11.4 11.4 0 0 0 4.79-1.32 1.6 1.6 0 0 0 .86-1.42v-3.9l-4.55 1.96a2.7 2.7 0 0 1-2.2 0z"/>',

        // Examinations: a paper on a clip, with a pencil laid across it.
        'examinations' => '<path d="M9.4 2.4a1 1 0 0 0-1 1v.5H6.2A1.7 1.7 0 0 0 4.5 5.6v14A1.7 1.7 0 0 0 6.2 21.3h8.05l1.3-3.6-1.55-1.55a1 1 0 0 1 0-1.42l1.9-1.9H8.6a.85.85 0 0 1 0-1.7h9a.85.85 0 0 1 .35.08l1.55-1.56V5.6a1.7 1.7 0 0 0-1.7-1.7h-2.2v-.5a1 1 0 0 0-1-1zM8.6 7.85h6.8a.85.85 0 0 1 0 1.7H8.6a.85.85 0 0 1 0-1.7m0 7.9h2.6a.85.85 0 0 1 0 1.7H8.6a.85.85 0 0 1 0-1.7"/><path d="M20.3 11.15a1.3 1.3 0 0 1 1.84 0l.71.71a1.3 1.3 0 0 1 0 1.84l-.83.83-2.55-2.55zM18.3 13.15l2.55 2.55-4.6 4.6-3.19.64.64-3.19z"/>',

        // Attendance: a calendar with the day ticked.
        'attendance' => '<path d="M8.15 1.9a.95.95 0 0 1 .95.95v1.1h5.8v-1.1a.95.95 0 1 1 1.9 0v1.1h1.4A2.1 2.1 0 0 1 20.3 6.05v1.4H3.7v-1.4A2.1 2.1 0 0 1 5.8 3.95h1.4v-1.1a.95.95 0 0 1 .95-.95"/><path d="M3.7 9.15h16.6v10.05a2.1 2.1 0 0 1-2.1 2.1H5.8a2.1 2.1 0 0 1-2.1-2.1zm12.03 3.2a.95.95 0 0 0-1.35 0l-3.66 3.66-1.4-1.4a.95.95 0 0 0-1.35 1.35l2.08 2.07a.95.95 0 0 0 1.34 0l4.34-4.33a.95.95 0 0 0 0-1.35"/>',

        // Finance: a banknote, the denomination reading through the middle.
        'finance' => '<path d="M5.4 2.7h13.2a2 2 0 0 1 2 2v14.6a2 2 0 0 1-2 2H5.4a2 2 0 0 1-2-2V4.7a2 2 0 0 1 2-2m6.85 3.05a4.2 4.2 0 0 0-3.06 1.2 3.3 3.3 0 0 0 .27 4.95c.6.48 1.36.72 2.4.95 1.04.24 1.4.38 1.6.55a.85.85 0 0 1-.08 1.4 2.3 2.3 0 0 1-1.38.38 3.5 3.5 0 0 1-2.14-.75 1 1 0 1 0-1.22 1.58 5.3 5.3 0 0 0 2.36 1.02v.72a1 1 0 1 0 2 0v-.73a3.9 3.9 0 0 0 1.7-.7 2.85 2.85 0 0 0 .2-4.44c-.6-.5-1.4-.75-2.5-1-.9-.2-1.28-.34-1.5-.5a1.3 1.3 0 0 1-.1-1.98 2.3 2.3 0 0 1 1.62-.63 3 3 0 0 1 1.74.56 1 1 0 1 0 1.14-1.64 5 5 0 0 0-1.8-.78v-.7a1 1 0 1 0-2 0z"/>',

        // Human Resources: a pair - the reference draws HR as two, not three.
        'hr' => '<circle cx="9.1" cy="7.7" r="3.4"/><path d="M9.1 12.5c-3.45 0-6.25 2.2-6.25 5.4v.85a1 1 0 0 0 1 1h10.5a1 1 0 0 0 1-1v-.85c0-3.2-2.8-5.4-6.25-5.4"/><circle cx="17.05" cy="8.5" r="2.7"/><path d="M17.05 12.9a6 6 0 0 0-1.35.15 7.4 7.4 0 0 1 2.05 5h2.55a1 1 0 0 0 1-1v-.6c0-2.55-2.2-3.55-4.25-3.55"/>',

        // Library: an open book, both leaves showing.
        'library' => '<path d="M11.05 5.35A9.8 9.8 0 0 0 4.6 3.3a1.4 1.4 0 0 0-1.4 1.4v12.15a1.4 1.4 0 0 0 1.4 1.4 8.4 8.4 0 0 1 5.5 1.72 1.5 1.5 0 0 0 .95.38zM12.95 5.35v15a1.5 1.5 0 0 0 .95-.38 8.4 8.4 0 0 1 5.5-1.72 1.4 1.4 0 0 0 1.4-1.4V4.7a1.4 1.4 0 0 0-1.4-1.4 9.8 9.8 0 0 0-6.45 2.05m1.7 3.4a.8.8 0 0 1 .8-.8h2.6a.8.8 0 0 1 0 1.6h-2.6a.8.8 0 0 1-.8-.8m0 3.5a.8.8 0 0 1 .8-.8h2.6a.8.8 0 0 1 0 1.6h-2.6a.8.8 0 0 1-.8-.8"/>',

        // Transport: a school bus seen head-on.
        'transport' => '<path d="M6.6 4.2h10.8a3.6 3.6 0 0 1 3.55 3l.85 5.35a2 2 0 0 1 .02.32v4.38a1.5 1.5 0 0 1-1.5 1.5h-.62v.85a1.35 1.35 0 0 1-2.7 0v-.85H7v.85a1.35 1.35 0 0 1-2.7 0v-.85h-.62a1.5 1.5 0 0 1-1.5-1.5v-4.38q0-.16.02-.32l.85-5.35a3.6 3.6 0 0 1 3.55-3m-.9 3.9-.5 3.15a.6.6 0 0 0 .6.7h12.4a.6.6 0 0 0 .6-.7l-.5-3.15a.9.9 0 0 0-.9-.75H6.6a.9.9 0 0 0-.9.75M6 14.4a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6m12 0a1.3 1.3 0 1 0 0 2.6 1.3 1.3 0 0 0 0-2.6"/>',

        // Boarding: the boarder's trunk, handle and clasps - the reference
        // draws the case, not a bed, and the case is what is reproduced.
        'boarding' => '<path d="M9.35 2.6h5.3a2.35 2.35 0 0 1 2.35 2.35v.8h1.6A2.4 2.4 0 0 1 21 8.15v9.9a2.4 2.4 0 0 1-2.4 2.4H5.4A2.4 2.4 0 0 1 3 18.05v-9.9a2.4 2.4 0 0 1 2.4-2.4H7v-.8A2.35 2.35 0 0 1 9.35 2.6m-.45 3.15h6.2v-.8a.45.45 0 0 0-.45-.45h-5.3a.45.45 0 0 0-.45.45zm-2.6 6.55a.95.95 0 0 0-.95.95v.6a.95.95 0 0 0 1.9 0v-.6a.95.95 0 0 0-.95-.95m11.4 0a.95.95 0 0 0-.95.95v.6a.95.95 0 0 0 1.9 0v-.6a.95.95 0 0 0-.95-.95m-8.5 2.35a.9.9 0 0 0-.66 1.5 4.7 4.7 0 0 0 6.92 0 .9.9 0 0 0-1.32-1.22 2.9 2.9 0 0 1-4.28 0 .9.9 0 0 0-.66-.28"/>',

        // Health: a heart carrying a pulse trace.
        'health' => '<path d="M12 20.85a1.5 1.5 0 0 1-.98-.36C7.5 17.5 2 13.06 2 8.62A5.62 5.62 0 0 1 7.62 3 5.6 5.6 0 0 1 12 5.1 5.6 5.6 0 0 1 16.38 3 5.62 5.62 0 0 1 22 8.62c0 4.44-5.5 8.88-9.02 11.87a1.5 1.5 0 0 1-.98.36m-1.62-12.4a.9.9 0 0 0-.83.55L8.7 10.6H6.9a.9.9 0 0 0 0 1.8h2.4a.9.9 0 0 0 .83-.55l.3-.7 1.42 3.3a.9.9 0 0 0 1.66-.01l.93-2.24h2.66a.9.9 0 1 0 0-1.8h-3.26a.9.9 0 0 0-.83.55l-.36.87-1.44-3.32a.9.9 0 0 0-.83-.55"/>',

        // Inventory & Assets: the strongbox - lid, handle and dial.
        'inventory' => '<path d="M10.2 2.15h3.6a2.1 2.1 0 0 1 2.1 2.1v.9h2.5A2.6 2.6 0 0 1 21 7.75v10.5a2.6 2.6 0 0 1-2.6 2.6H5.6A2.6 2.6 0 0 1 3 18.25V7.75a2.6 2.6 0 0 1 2.6-2.6h2.5v-.9a2.1 2.1 0 0 1 2.1-2.1M10 5.15h4v-.9a.2.2 0 0 0-.2-.2h-3.6a.2.2 0 0 0-.2.2zm2 4.35a3.6 3.6 0 1 0 0 7.2 3.6 3.6 0 0 0 0-7.2m0 1.9a1.7 1.7 0 1 1 0 3.4 1.7 1.7 0 0 1 0-3.4"/>',

        // Communications: the notice board - a screen carrying a message.
        'communications' => '<path d="M4 3.4h16A2.2 2.2 0 0 1 22.2 5.6v9.6A2.2 2.2 0 0 1 20 17.4h-6.05v1.7h2.6a.95.95 0 0 1 0 1.9H7.45a.95.95 0 0 1 0-1.9h2.6v-1.7H4a2.2 2.2 0 0 1-2.2-2.2V5.6A2.2 2.2 0 0 1 4 3.4m13.06 2.62a.9.9 0 0 0-.64.26l-9.4 9.4h2.55l8.13-8.13a.9.9 0 0 0-.64-1.53"/>',

        // Reports & Analytics: a column chart standing on its axis.
        'reports' => '<path d="M3.6 19.05h16.8a.95.95 0 0 1 0 1.9H3.6a.95.95 0 0 1 0-1.9M5.9 11.4a.9.9 0 0 1 .9.9v4.85H4.1a.9.9 0 0 1-.9-.9V12.3a.9.9 0 0 1 .9-.9zm4.15-4.6a.9.9 0 0 1 .9.9v9.45H8.25a.9.9 0 0 1-.9-.9V7.7a.9.9 0 0 1 .9-.9zm4.15 2.6a.9.9 0 0 1 .9.9v6.85H12.4a.9.9 0 0 1-.9-.9V10.3a.9.9 0 0 1 .9-.9zm4.15-6.3a.9.9 0 0 1 .9.9v13.15h-2.7a.9.9 0 0 1-.9-.9V4a.9.9 0 0 1 .9-.9z"/>',

        // Security & Access: the toothed cog with an open core.
        'security' => '<path d="M12 1.9a1.5 1.5 0 0 0-1.45 1.12l-.26.99a8.4 8.4 0 0 0-1.2.5l-.88-.51a1.5 1.5 0 0 0-1.81.23L4.99 5.74a1.5 1.5 0 0 0-.23 1.81l.51.88a8.4 8.4 0 0 0-.5 1.2l-.99.26A1.5 1.5 0 0 0 2.66 11.34v2.02a1.5 1.5 0 0 0 1.12 1.45l.99.26q.2.62.5 1.2l-.51.88a1.5 1.5 0 0 0 .23 1.81l1.41 1.41a1.5 1.5 0 0 0 1.81.23l.88-.51q.58.3 1.2.5l.26.99A1.5 1.5 0 0 0 12 22.7h.68a1.5 1.5 0 0 0 1.45-1.12l.26-.99q.62-.2 1.2-.5l.88.51a1.5 1.5 0 0 0 1.81-.23l1.41-1.41a1.5 1.5 0 0 0 .23-1.81l-.51-.88q.3-.58.5-1.2l.99-.26a1.5 1.5 0 0 0 1.12-1.45v-2.02a1.5 1.5 0 0 0-1.12-1.45l-.99-.26a8.4 8.4 0 0 0-.5-1.2l.51-.88a1.5 1.5 0 0 0-.23-1.81l-1.41-1.41a1.5 1.5 0 0 0-1.81-.23l-.88.51a8.4 8.4 0 0 0-1.2-.5l-.26-.99A1.5 1.5 0 0 0 12.68 1.9zm.34 6.35a4.1 4.1 0 1 1 0 8.2 4.1 4.1 0 0 1 0-8.2m0 1.95a2.15 2.15 0 1 0 0 4.3 2.15 2.15 0 0 0 0-4.3"/>',

        // System Administration: the same cog drawn heavier, so the two
        // administrative groups are distinguishable at 18px without either
        // borrowing another domain's picture.
        'administration' => '<path d="M10.32 2.2a1.7 1.7 0 0 0-1.68 1.44l-.2 1.3a7.6 7.6 0 0 0-1.5.87l-1.23-.47a1.7 1.7 0 0 0-2.08.76l-1.35 2.34a1.7 1.7 0 0 0 .4 2.18l1.03.83a7.7 7.7 0 0 0 0 1.74l-1.03.83a1.7 1.7 0 0 0-.4 2.18l1.35 2.34a1.7 1.7 0 0 0 2.08.76l1.23-.47q.7.53 1.5.87l.2 1.3a1.7 1.7 0 0 0 1.68 1.44h2.7a1.7 1.7 0 0 0 1.68-1.44l.2-1.3a7.6 7.6 0 0 0 1.5-.87l1.23.47a1.7 1.7 0 0 0 2.08-.76l1.35-2.34a1.7 1.7 0 0 0-.4-2.18l-1.03-.83a7.7 7.7 0 0 0 0-1.74l1.03-.83a1.7 1.7 0 0 0 .4-2.18l-1.35-2.34a1.7 1.7 0 0 0-2.08-.76l-1.23.47a7.6 7.6 0 0 0-1.5-.87l-.2-1.3A1.7 1.7 0 0 0 13.02 2.2zM11.67 8a4 4 0 1 1 0 8 4 4 0 0 1 0-8m0 2.05a1.95 1.95 0 1 0 0 3.9 1.95 1.95 0 0 0 0-3.9"/>',
    ];

    /*
     * TOP BAR glyphs are OUTLINE, and that is not an inconsistency - it is
     * what the reference does. Cropped at 2.2x, the sidebar's eighteen are
     * solid fills while the bell, envelope, calendar, clock, campus and
     * refresh in the bar are all thin open strokes. Two registers, one per
     * surface: chrome-on-dark is solid, chrome-on-white is outline.
     */
    $outlines = [
        // Campus: the classical facade - pediment over columns.
        'campus' => '<path d="M12 3.2 3.4 7.2v1.3h17.2V7.2z"/><path d="M5.6 10.5v7.2M9.9 10.5v7.2M14.1 10.5v7.2M18.4 10.5v7.2"/><path d="M3.4 19.9h17.2"/>',
        'calendar' => '<rect x="3.6" y="5" width="16.8" height="15.4" rx="2.4"/><path d="M3.6 9.9h16.8M8.3 2.9v4M15.7 2.9v4"/><path d="M7.6 13.1h2.2M7.6 16.5h2.2M12.9 13.1h2.2M12.9 16.5h2.2"/>',
        'bell' => '<path d="M18.2 15.9V9.9a6.2 6.2 0 1 0-12.4 0v6l-1.3 2.3h15z"/><path d="M9.7 18.9a2.3 2.3 0 0 0 4.6 0"/>',
        'mail' => '<rect x="2.9" y="4.9" width="18.2" height="14.2" rx="2.2"/><path d="m3.6 6.7 8.4 6 8.4-6"/>',
        'clock' => '<circle cx="12" cy="12" r="9.1"/><path d="M12 6.6V12l3.6 2.1"/>',
        'refresh' => '<path d="M20.2 11.3a8.2 8.2 0 0 0-14-4.9L3.8 8.8"/><path d="M3.8 4.4v4.4h4.4"/><path d="M3.8 12.7a8.2 8.2 0 0 0 14 4.9l2.4-2.4"/><path d="M20.2 19.6v-4.4h-4.4"/>',
        'menu' => '<path d="M4 6.6h16M4 12h16M4 17.4h16"/>',
        'chevron_down' => '<path d="m6.2 9.4 5.8 5.6 5.8-5.6"/>',
        'chevron_right' => '<path d="m9.4 5.8 6 6.2-6 6.2"/>',
        'search' => '<circle cx="10.9" cy="10.9" r="7.1"/><path d="m16.1 16.1 4.2 4.2"/>',
        // Every "View all ..." footer in the reference ends in a long arrow.
        // A chevron is a different sign - it reads as "expand", not "go".
        'arrow_right' => '<path d="M4.2 12h15.6"/><path d="m13.4 5.6 6.4 6.4-6.4 6.4"/>',
        'verified' => '<path d="M12 2.6 14.4 4.9l3.2-.3.5 3.2 2.7 1.8-1.5 2.9 1.5 2.9-2.7 1.8-.5 3.2-3.2-.3L12 21.4l-2.4-2.3-3.2.3-.5-3.2-2.7-1.8L4.7 12.5 3.2 9.6l2.7-1.8.5-3.2 3.2.3z"/><path d="m8.6 12.3 2.3 2.3 4.5-4.6"/>',
    ];

    $isOutline = isset($outlines[$name]);
    $glyph = $isOutline ? $outlines[$name] : ($glyphs[$name] ?? '<circle cx="12" cy="12" r="3.2"/>');
@endphp

@if ($isOutline)
    <svg {{ $attributes->merge(['class' => 'h-[18px] w-[18px] shrink-0']) }}
         viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7"
         stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $glyph !!}</svg>
@else
    <svg {{ $attributes->merge(['class' => 'h-[18px] w-[18px] shrink-0']) }}
         viewBox="0 0 24 24" fill="currentColor" aria-hidden="true">{!! $glyph !!}</svg>
@endif
