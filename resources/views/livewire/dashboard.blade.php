{{-- The landing screen, built to `frontend images/super admin dashbaord.png`.

     MEASURED (working: docs/superpowers/specs/2026-08-20-admin-dashboard-measurements.md):

       content width      x 289..1518            -> 1230px inside a 20px gutter
       row gap            14-16px of clear ground -> 16px
       KPI strip          y 117..242              -> 126px tall
       row 2              y 258..560              -> 303px
       row 3              y 570..760              -> 191px
       row 4              y 770..972              -> 203px

     COLUMN TRACKS. Rows 2 and 3 agree to within 4px on a NON-equal
     three-column track (371 : 394 : 445), twice - that repetition is
     evidence of intent, so it is reproduced as measured rather than
     "tidied" into equal thirds. The KPI row does not agree with itself
     (six cards at its measured widths plus its measured gutters sum to
     ~1286px inside a 1230px area, and its sixth card measures 149px
     against ~205px for five identical-looking siblings), so that row - and
     only that row - is normalised to six equal columns.

     COMPOSED PER ROLE, as before. Every panel below renders only when its
     figure came back non-null, and the action returns null both for "you
     may not see this" and for "this could not be read". A role with none of
     them still lands on the role-composed strip and the empty state under
     it, never a blank page.

     Do NOT reason about pixel sizes from the Tailwind utility names here:
     the root font-size is 17px, so text-sm is ~15px and text-2xl ~25.5px -
     which is exactly why the reference's measured type scale (15, 17, 26)
     is reproducible with the app's own scale. --}}
<div class="min-w-0 space-y-4">

    {{-- Panels use a COMPACT empty line, not x-empty-state.

         x-empty-state is the full-width screen scaffold - a 56px icon disc
         inside a dashed box with py-10 - and it is right there. Inside a
         191px dashboard panel it was taller than the panel itself and added
         ~95px to the row on its own (layout-diff.php). The message is the
         part that matters; the scaffolding around it is not. --}}

    {{-- ── KPI strip ────────────────────────────────────────────────────── --}}
    @php
        $attendance = $admin['attendance_today'] ?? null;
        $modules = $admin['modules'] ?? null;
        $fees = $admin['fees_this_month'] ?? null;
    @endphp

    {{-- auto-fit, NOT a fixed six-column track and NOT an interpolated count.

         Six cards is what the reference shows, but every one is
         permission-gated and two are also conditional on there being data
         (no register taken yet today means no attendance card), so a fixed
         `grid-cols-6` leaves a card-shaped hole at the end of the row for
         most readers.

         The obvious fix - computing the count in PHP and interpolating it
         into `2xl:[grid-template-columns:repeat(N,...)]` - does not work
         and fails SILENTLY: Tailwind scans source files for complete class
         names at build time, an interpolated one exists only at render, so
         no rule is ever generated and the row quietly falls back to the
         previous breakpoint. It laid five cards out as 3 + 2.

         auto-fit with a floor is one static class that handles every count:
         the tracks divide the row between however many cards rendered, and
         wrap only when they would go under 185px. --}}
    <div class="grid gap-4 [grid-template-columns:repeat(auto-fit,minmax(185px,1fr))]">
        @if (($admin['students_total'] ?? null) !== null)
            <x-shell.stat-card :label="__('opes.dashboard.total_students')"
                               :value="number_format($admin['students_total'])"
                               icon="students" tone="green"
                               :footer-label="__('opes.dashboard.view_all_students')"
                               :footer-url="route('students.index', absolute: false)"/>
        @endif

        @if (($admin['staff_total'] ?? null) !== null)
            <x-shell.stat-card :label="__('opes.dashboard.total_staff')"
                               :value="number_format($admin['staff_total'])"
                               icon="staff" tone="green"
                               :footer-label="__('opes.dashboard.view_all_staff')"
                               :footer-url="route('hr.index', absolute: false)"/>
        @endif

        @if (($admin['classes_total'] ?? null) !== null)
            <x-shell.stat-card :label="__('opes.dashboard.total_classes')"
                               :value="number_format($admin['classes_total'])"
                               icon="academics" tone="green"
                               :footer-label="__('opes.dashboard.view_all_classes')"
                               :footer-url="route('classes.index', absolute: false)"/>
        @endif

        @if ($canViewAttendance)
            {{-- ALWAYS rendered for a reader who may see attendance, even
                 with no register taken yet - that is why the reference's
                 strip is six cards wide and this one was five.

                 The em dash is the whole point of rendering it anyway: "no
                 register has been taken today" is an operational fact a head
                 teacher needs at 9am, and hiding the card states nothing
                 while showing 0% states something false.

                 The percentage is a note rather than a link because it IS
                 the headline the card exists for - the count is the
                 supporting figure. --}}
            <x-shell.stat-card :label="__('opes.dashboard.tile_attendance')"
                               :value="$attendance['present'] === null ? null : number_format($attendance['present'])"
                               icon="attendance" tone="gold"
                               :note="$todaysAttendanceRate === null
                                   ? __('opes.dashboard.no_register_today')
                                   : __('opes.dashboard.rate_present', ['rate' => $todaysAttendanceRate])"
                               :note-tone="$todaysAttendanceRate === null ? 'text-charcoal/55' : 'text-success'"/>
        @endif

        @if ($fees !== null)
            <x-shell.stat-card :label="__('opes.dashboard.fees_collection_month')"
                               :value="\App\Support\Money\Money::of($fees)->format()"
                               icon="finance" tone="green"
                               :footer-label="__('opes.dashboard.view_collection')"
                               :footer-url="route('fees.invoices.index', absolute: false)"/>
        @endif

        @if ($modules !== null)
            <x-shell.stat-card :label="__('opes.dashboard.active_modules')"
                               :value="$modules['built'].' / '.$modules['total']"
                               icon="modules" tone="red"
                               :note="__('opes.dashboard.percent_operational', ['percent' => $modules['percent']])"/>
        @endif
    </div>

    {{-- ── Row 2: actions, notifications, calendar ──────────────────────── --}}
    {{-- The measured 371 : 394 : 445 track, as fr weights so it holds its
         proportions at any width instead of only at 1536. --}}
    <div class="grid grid-cols-1 gap-4 xl:[grid-template-columns:371fr_394fr_445fr]">

        @if ($quickActions !== [])
            <x-shell.panel :title="__('opes.dashboard.quick_actions')"
                           :footer-label="__('opes.dashboard.view_all_actions')"
                           :footer-url="route('reports.hub', absolute: false)"
                           footer-permission="reports.view">
                {{-- MEASURED off the reference's own panel: the tile block is
                     y 306..521 for three rows, so each tile is 69px tall on a
                     ~7px gutter, and each is 106px wide across the 371px
                     column. The tiles were 88px tall, which added a fourth
                     row's worth of height and pushed every row below this
                     panel down the page.

                     The icon is SOLID, from the chrome register - the
                     reference's tile glyphs are filled shapes, and the
                     outline set next door is a different picture at this
                     size, not a near match. --}}
                @php
                    // Quick-action key -> solid glyph. The reference uses ONE
                    // person+ sign for all three "enrol somebody" routes.
                    $tileGlyphs = [
                        'add_student' => 'person_add',
                        'add_staff' => 'person_add',
                        'new_admission' => 'person_add',
                        'add_user' => 'person_add',
                        'academic_year' => 'academics',
                        'bulk_import' => 'cloud_up',
                        'backup_database' => 'shield',
                        'reports' => 'reports',
                        'go_live_setup' => 'checklist',
                    ];
                @endphp

                {{-- 3px gutter and a WARM border, both sampled across the
                     reference's own tile boundary at y=350: tile white runs to
                     x 411, the gutter is #F8F6F5 for 2px, tile two resumes at
                     414, and the border line reads #F2F1ED. The default
                     divider (#E8E9EB) is cooler and darker than that, which
                     is what made the tile grid read as a table. --}}
                <div class="grid grid-cols-3 gap-[3px]">
                    @foreach ($quickActions as $action)
                        <a href="{{ $action['url'] }}" wire:key="action-{{ $action['key'] }}" wire:navigate
                           class="group flex h-[69px] flex-col items-center justify-center gap-1 rounded-[10px] border border-[#F2F1ED] bg-white px-1.5 text-center transition hover:border-primary hover:shadow-sm">
                            <x-shell.icon :name="$tileGlyphs[$action['key']] ?? 'modules'"
                                          class="h-[26px] w-[26px] text-primary"/>
                            <span class="w-full truncate text-[11px] leading-none text-charcoal group-hover:text-primary">
                                {{ $action['label'] }}
                            </span>
                        </a>
                    @endforeach
                </div>
            </x-shell.panel>
        @endif

        <x-shell.panel :title="__('opes.dashboard.notifications')" icon="bell" icon-tone="text-shell-gold-deep"
                       :footer-label="__('opes.dashboard.view_all_notifications')"
                       :footer-url="route('communication.messages', absolute: false)">
            @if ($alerts === [])
                <p class="py-3 text-[14px] text-charcoal/55">{{ __('opes.dashboard.no_alerts') }}</p>
            @else
                {{-- ONE LINE per row, on the reference's measured 38px pitch
                     (ink bands at y 322, 360, 398, 435, 474) at 15px.

                     The line is the DETAIL, not the label: the reference's
                     rows read "Term 1 exams will begin on 15 July 2026" -
                     a sentence a reader can act on - and this panel was
                     stacking a heading above that sentence, which doubled
                     every row's height and pushed 56px of the page down.
                     The label is not lost; it is the row's title, which is
                     also what a screen reader announces. --}}
                <ul class="divide-y divide-shell-divider">
                    @foreach ($alerts as $alert)
                        <li class="flex h-[38px] items-center gap-2.5" title="{{ $alert->label }}">
                            {{-- The dot carries severity: red for a failure,
                                 amber for a warning. Colour is never the ONLY
                                 carrier - the status word is in the title
                                 alongside the label. --}}
                            <span class="h-2 w-2 shrink-0 rounded-full
                                         {{ $alert->status->value === 'fail' ? 'bg-shell-alert' : 'bg-warning' }}"
                                  aria-hidden="true"></span>
                            <span class="min-w-0 flex-1 truncate text-[15px] leading-none text-charcoal">
                                {{ $alert->detail !== '' ? $alert->detail : $alert->label }}
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shell.panel>

        <x-shell.panel :title="__('opes.dashboard.upcoming_events')" icon="calendar" icon-tone="text-primary"
                       :footer-label="__('opes.dashboard.view_full_calendar')"
                       :footer-url="route('academics.settings', absolute: false)"
                       footer-permission="academics.manage">
            @if (($admin['upcoming_events'] ?? []) === [])
                <p class="py-3 text-[14px] text-charcoal/55">{{ __('opes.dashboard.no_events') }}</p>
            @else
                <ul class="space-y-2.5 py-1">
                    @foreach ($admin['upcoming_events'] as $event)
                        <li class="flex items-center gap-3">
                            <span class="flex h-[46px] w-[46px] shrink-0 flex-col items-center justify-center rounded-lg bg-surface-green leading-none">
                                <span class="text-[18px] font-bold text-primary">{{ $event['on']->format('j') }}</span>
                                <span class="mt-0.5 text-[10px] font-semibold uppercase tracking-wide text-primary/70">
                                    {{ $event['on']->translatedFormat('M') }}
                                </span>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-[15px] font-medium text-charcoal">{{ $event['title'] }}</span>
                                <span class="block truncate text-[13px] text-charcoal/60">{{ $event['detail'] }}</span>
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shell.panel>
    </div>

    {{-- ── Row 3: money and roll ────────────────────────────────────────── --}}
    @php
        $overview = $admin['financial_overview'] ?? null;
        $balances = $admin['top_balances'] ?? null;
        $strength = $admin['student_strength'] ?? null;
    @endphp

    @if ($overview !== null || $balances !== null || $strength !== null)
        <div class="grid grid-cols-1 gap-4 xl:[grid-template-columns:371fr_395fr_449fr]">

            @if ($overview !== null)
                <x-shell.panel :title="__('opes.dashboard.financial_overview')"
                               :footer-label="__('opes.dashboard.view_full_report')"
                               :footer-url="route('reports.financial', absolute: false)"
                               footer-permission="ledger.view">
                    <div class="flex items-center gap-3 py-1">
                        <span class="flex h-[46px] w-[46px] shrink-0 items-center justify-center rounded-xl bg-shell-disc">
                            <x-shell.icon name="finance" class="h-[24px] w-[24px] text-white"/>
                        </span>
                        <dl class="min-w-0 flex-1">
                            @foreach ([
                                ['key' => 'fee_collection', 'value' => $overview['collection'], 'dot' => 'bg-success'],
                                ['key' => 'expenses', 'value' => $overview['expenses'], 'dot' => 'bg-shell-alert'],
                                ['key' => 'balance', 'value' => $overview['balance'], 'dot' => 'bg-warning'],
                            ] as $line)
                                <div class="flex h-[23px] items-center gap-2">
                                    <span class="h-2 w-2 shrink-0 rounded-full {{ $line['dot'] }}" aria-hidden="true"></span>
                                    <dt class="min-w-0 flex-1 truncate text-[14px] text-charcoal/80">
                                        {{ __('opes.dashboard.'.$line['key']) }}
                                    </dt>
                                    <dd class="shrink-0 text-[14px] font-semibold text-charcoal">
                                        {{ \App\Support\Money\Money::of($line['value'])->format() }}
                                    </dd>
                                </div>
                            @endforeach
                        </dl>
                    </div>
                </x-shell.panel>
            @endif

            @if ($balances !== null)
                <x-shell.panel :title="__('opes.dashboard.top_fee_balances')"
                               :footer-label="__('opes.dashboard.view_all')"
                               :footer-url="route('fees.invoices.index', absolute: false)"
                               footer-permission="fee.view">
                    @if ($balances === [])
                        <p class="py-3 text-[14px] text-charcoal/55">{{ __('opes.dashboard.no_balances') }}</p>
                    @else
                        <ol class="py-1">
                            @foreach ($balances as $index => $balance)
                                <li class="flex h-[23px] items-center gap-2 text-[14px]">
                                    <span class="w-4 shrink-0 text-charcoal/50">{{ $index + 1 }}.</span>
                                    <span class="min-w-0 flex-1 truncate text-charcoal">{{ $balance['name'] }}</span>
                                    <span class="shrink-0 font-medium text-charcoal">
                                        {{ \App\Support\Money\Money::of($balance['amount'])->format() }}
                                    </span>
                                </li>
                            @endforeach
                        </ol>
                    @endif
                </x-shell.panel>
            @endif

            @if ($strength !== null)
                <x-shell.panel :title="__('opes.dashboard.student_strength')"
                               :footer-label="__('opes.dashboard.view_full_report')"
                               :footer-url="route('reports.students-guardians', absolute: false)"
                               footer-permission="reports.view">
                    <dl class="py-1">
                        @foreach ([
                            ['key' => 'total_students', 'value' => $strength['total'], 'icon' => 'students'],
                            ['key' => 'male_students', 'value' => $strength['male'], 'icon' => 'school_network'],
                            ['key' => 'female_students', 'value' => $strength['female'], 'icon' => 'school_network'],
                            ['key' => 'day_students', 'value' => $strength['day'], 'icon' => 'boarding'],
                            ['key' => 'boarding_students', 'value' => $strength['boarding'], 'icon' => 'staff'],
                        ] as $line)
                            <div class="flex h-[23px] items-center gap-2.5">
                                <x-shell.icon :name="$line['icon']" class="h-[18px] w-[18px] shrink-0 text-primary"/>
                                <dt class="min-w-0 flex-1 truncate text-[14px] text-charcoal/80">
                                    {{ __('opes.dashboard.'.$line['key']) }}
                                </dt>
                                <dd class="shrink-0 text-[14px] font-semibold text-charcoal">
                                    {{ number_format($line['value']) }}
                                </dd>
                            </div>
                        @endforeach
                    </dl>
                </x-shell.panel>
            @endif
        </div>
    @endif

    {{-- ── Row 4: activity and alerts ───────────────────────────────────── --}}
    @php
        $activities = $admin['recent_activities'] ?? null;
    @endphp

    <div class="grid grid-cols-1 gap-4 xl:[grid-template-columns:552fr_667fr]">

        @if ($activities !== null)
            <x-shell.panel :title="__('opes.dashboard.recent_activities')"
                           :footer-label="__('opes.dashboard.view_all_activities')"
                           :footer-url="route('audit.index', absolute: false)"
                           footer-permission="audit.view">
                @if ($activities === [])
                    <p class="py-3 text-[14px] text-charcoal/55">{{ __('opes.dashboard.no_activities') }}</p>
                @else
                    <ul class="divide-y divide-shell-divider">
                        @foreach ($activities as $activity)
                            <li class="flex items-center gap-2.5 py-2">
                                <span class="h-2 w-2 shrink-0 rounded-full bg-success" aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate text-[14px] text-charcoal">{{ $activity['action'] }}</span>
                                <span class="w-24 shrink-0 truncate text-[13px] text-charcoal/60">{{ $activity['module'] }}</span>
                                <span class="shrink-0 text-[13px] text-charcoal/50">{{ $activity['at']->diffForHumans(short: true) }}</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-shell.panel>
        @endif

        {{-- The remedy is the whole point of this panel: a red light with no
             instruction is anxiety, not information (08-operations §7). Each
             row keeps its remedy and, where a backup is genuinely the fix,
             its button. --}}
        <x-shell.panel :title="__('opes.dashboard.system_alerts')">
            @if ($alerts === [])
                <p class="py-3 text-[14px] text-charcoal/55">{{ __('opes.dashboard.no_alerts') }}</p>
            @else
                <ul class="divide-y divide-shell-divider">
                    @foreach ($alerts as $alert)
                        <li class="py-2">
                            <div class="flex items-center gap-2.5">
                                <span class="h-2.5 w-2.5 shrink-0 rounded-full
                                             {{ $alert->status->value === 'fail' ? 'bg-shell-alert' : 'bg-warning' }}"
                                      aria-hidden="true"></span>
                                <span class="min-w-0 flex-1 truncate text-[14px] text-charcoal">{{ $alert->label }}</span>
                                <x-status-pill :status="$alert->status->value"/>
                            </div>

                            @if (str_starts_with($alert->key, 'backup.') || $alert->key === 'queue.heartbeat')
                                @can('backup.run') @if (str_starts_with($alert->key, 'backup.'))
                                    <a href="{{ route('operations.backups') }}" wire:navigate
                                       class="mt-1.5 inline-block rounded-lg border border-primary bg-primary px-3 py-1.5 text-[13px] font-medium text-white transition hover:bg-primary/90">
                                        {{ __('opes.dashboard.run_a_backup') }}
                                    </a>
                                @endif @endcan
                                <details class="mt-1 text-[12px] text-charcoal/55">
                                    <summary class="cursor-pointer">{{ __('opes.dashboard.for_your_it_provider') }}</summary>
                                    <p class="mt-1">{{ __('opes.dashboard.it_provider_backup_note') }}</p>
                                </details>
                            @elseif ($alert->remedy !== '')
                                <p class="mt-1 pl-5 text-[13px] text-charcoal/70">
                                    <span class="font-medium">{{ __('opes.dashboard.remedy') }}:</span>
                                    {{ $alert->remedy }}
                                </p>
                            @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </x-shell.panel>
    </div>

    {{-- ── The role-composed strip ──────────────────────────────────────── --}}
    {{-- Unchanged in what it does: RoleDashboard still decides what a
         Teacher, a Bursar or a Librarian lands on. It sits BELOW the
         administrator panels because for a non-administrator every panel
         above returns null and this becomes the top of the page. --}}
    @if ($panels !== [])
        <section aria-labelledby="opes-dashboard-overview">
            <h2 id="opes-dashboard-overview" class="mb-2.5 text-[13px] font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.dashboard.overview') }}
            </h2>

            {{-- `grid-cols-1` is LOAD-BEARING, not decoration. Without it
                 there is no grid-template-columns below sm, the cards land in
                 an implicit `auto` track floored by their own min-content,
                 and `truncate` on the sub-line sets white-space: nowrap - so
                 that floor becomes the width of the LONGEST sub-line on the
                 page and every card is silently clipped by main's
                 overflow-x-hidden. --}}
            <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3 2xl:grid-cols-4">
                @foreach ($panels as $panel)
                    <x-kpi-card wire:key="panel-{{ $panel['key'] }}"
                                :label="__('opes.dashboard.panel_'.$panel['key'])"
                                :value="$panel['value']"
                                :sub="$panel['sub'] ?? __('opes.dashboard.panel_'.$panel['key'].'_sub')"
                                :tone="$panel['tone']"
                                :href="$panel['route'] === null ? null : route($panel['route'], absolute: false)">
                        <x-slot:icon>
                            <x-opes-nav-icon :nav-key="$panel['icon']" class="h-5 w-5"/>
                        </x-slot:icon>

                        @if ($panel['key'] === 'system_health' && $healthSummary !== null)
                            <x-slot:display>
                                <x-status-pill :status="$healthSummary->value"/>
                            </x-slot:display>
                        @endif
                    </x-kpi-card>
                @endforeach
            </div>
        </section>
    @elseif (($admin['students_total'] ?? null) === null && $quickActions === [])
        {{-- The empty-state rule: a role with nothing to show still lands on
             something that says what it is. Never a blank grid. --}}
        <section class="rounded-xl border border-shell-divider bg-shell-surface p-6 text-center shadow-sm">
            <p class="text-base font-medium text-charcoal">{{ __('opes.dashboard.empty_title') }}</p>
            <p class="mx-auto mt-1 max-w-prose text-sm text-text-secondary">{{ __('opes.dashboard.empty_body') }}</p>
        </section>
    @endif

    {{-- ── "What's open right now" (08-operations §6.4) ─────────────────── --}}
    {{-- The panel decides its own visibility (fee.view or ledger.view) and
         renders nothing for anyone else. --}}
    @livewire(\App\Modules\Operations\Livewire\WhatsOpenPanel::class)

    {{-- ── Footer line ──────────────────────────────────────────────────── --}}
    <p class="flex flex-wrap items-center justify-between gap-2 pt-1 pb-2 text-[13px] text-charcoal/60">
        <span>{!! __('opes.dashboard.copyright', ['year' => now()->year]) !!}</span>
        <span>{{ config('app.name') }}</span>
    </p>
</div>
