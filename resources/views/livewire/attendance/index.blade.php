{{-- Attendance Management — 09-ui §8.7: KPI row (Total · Present Today ·
     Absent Today · Late Today · Rate this month), Attendance Overview donut,
     today's registers, Class Calendar. Every figure renders "—" when no
     register backs it — NEVER 0% (C5). --}}

@php
    use App\Modules\Attendance\Domain\RegisterStatus;

    $pct = static fn (?float $rate): ?string => $rate === null ? null : number_format($rate * 100, 1).'%';

    // Donut shares (month scope) — only drawn when a rate exists.
    $donutRate = $monthRate;
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('attendance.breadcrumb_dashboard') }}</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('attendance.breadcrumb_attendance') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('attendance.title') }}</h1>

        <div class="flex flex-wrap items-center gap-2">
            @can(\App\Modules\Identity\Domain\Permission::AttendanceTake->value)
                <a href="{{ url('/attendance/take') }}"
                   class="flex items-center gap-1.5 rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                    <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" d="M12 5v14M5 12h14"/>
                    </svg>
                    {{ __('attendance.take_title') }}
                </a>
            @endcan
            <a href="{{ url('/attendance/coverage') }}"
               class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('attendance.coverage_title') }}
            </a>
        </div>
    </div>

    @if (! $hasYear)
        <x-empty-state :message="__('attendance.no_year')"/>
    @else
        {{-- ── KPI row ────────────────────────────────────────────────── --}}
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
            <x-kpi-card :label="__('attendance.kpi_total')" :value="$totalStudents" icon-bg="bg-primary"
                        :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4z&quot;/></svg>'"/>
            <x-kpi-card :label="__('attendance.kpi_present_today')" :value="$presentToday" icon-bg="bg-primary"
                        :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M5 13l4 4L19 7&quot;/></svg>'"/>
            <x-kpi-card :label="__('attendance.kpi_absent_today')" :value="$absentToday" icon-bg="bg-heritage-red"
                        :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M6 18L18 6M6 6l12 12&quot;/></svg>'"/>
            <x-kpi-card :label="__('attendance.kpi_late_today')" :value="$lateToday" icon-bg="bg-heritage-yellow"
                        :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z&quot;/></svg>'"/>
            <x-kpi-card :label="__('attendance.kpi_month_rate')" :value="$pct($monthRate)" icon-bg="bg-badge-blue"
                        :icon="'<svg class=&quot;h-5 w-5&quot; viewBox=&quot;0 0 24 24&quot; fill=&quot;none&quot; stroke=&quot;currentColor&quot; stroke-width=&quot;2&quot;><path stroke-linecap=&quot;round&quot; d=&quot;M3 3v18h18M8 17V9m4 8V5m4 12v-6&quot;/></svg>'"/>
        </div>

        <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
            {{-- ── Today's registers ──────────────────────────────────── --}}
            <div class="min-w-0 xl:col-span-2">
                <div class="rounded border border-sand bg-white">
                    <div class="border-b border-sand px-4 py-3">
                        <h2 class="text-sm font-semibold text-charcoal">
                            {{ __('attendance.todays_registers') }} — {{ $today->toDateString() }}
                        </h2>
                    </div>

                    @if ($registerRows === [])
                        <div class="px-4 py-6">
                            <x-empty-state :message="__('attendance.no_registers_today')"/>
                        </div>
                    @else
                        <div class="overflow-x-auto">
                            <table class="min-w-full divide-y divide-sand text-sm">
                                <thead class="bg-cream text-left text-xs uppercase tracking-wide text-charcoal/60">
                                    <tr>
                                        <th scope="col" class="px-4 py-2">{{ __('attendance.col_class') }}</th>
                                        <th scope="col" class="px-4 py-2">{{ __('attendance.col_session') }}</th>
                                        <th scope="col" class="px-4 py-2">{{ __('attendance.col_taken_by') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.expected') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.status.present') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.status.absent') }}</th>
                                        <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.status.late') }}</th>
                                        <th scope="col" class="px-4 py-2">{{ __('attendance.col_status') }}</th>
                                    </tr>
                                </thead>
                                <tbody class="divide-y divide-sand bg-white">
                                    @foreach ($registerRows as $row)
                                        <tr wire:key="register-{{ $row->id }}">
                                            <td class="px-4 py-2 font-medium text-charcoal">{{ $row->class_group_name }}</td>
                                            <td class="px-4 py-2 text-charcoal/70">{{ __('attendance.session.'.$row->session) }}</td>
                                            <td class="px-4 py-2 text-charcoal/70">{{ $row->taken_by_name }}</td>
                                            <td class="px-4 py-2 text-right">{{ $row->expected_count }}</td>
                                            <td class="px-4 py-2 text-right">{{ $row->present_count }}</td>
                                            <td class="px-4 py-2 text-right">{{ $row->absent_count }}</td>
                                            <td class="px-4 py-2 text-right">{{ $row->late_count }}</td>
                                            <td class="px-4 py-2">
                                                <x-status-pill :status="$row->status === 'open' ? 'amber' : 'ok'"
                                                               :label="__('attendance.register_status.'.$row->status)"/>
                                            </td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>
                    @endif
                </div>
            </div>

            {{-- ── Right rail: overview donut + class calendar ────────── --}}
            <div class="min-w-0 space-y-4">
                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">{{ __('attendance.overview_title') }}</h2>

                    @if ($donutRate === null)
                        <p class="mt-3 text-2xl font-semibold text-charcoal" title="{{ __('opes.ui.no_data') }}">—</p>
                        <p class="mt-1 text-xs text-charcoal/60">{{ __('attendance.no_registers_yet') }}</p>
                    @else
                        @php $deg = (int) round($donutRate * 360); @endphp
                        <div class="mt-3 flex items-center gap-4">
                            <div class="relative h-28 w-28 shrink-0 rounded-full"
                                 style="background: conic-gradient(#1D6F42 0deg {{ $deg }}deg, #C1272D {{ $deg }}deg 360deg);"
                                 role="img" aria-label="{{ __('attendance.kpi_month_rate') }}: {{ $pct($donutRate) }}">
                                <div class="absolute inset-3 flex items-center justify-center rounded-full bg-white">
                                    <span class="text-lg font-semibold text-charcoal">{{ $pct($donutRate) }}</span>
                                </div>
                            </div>
                            <ul class="space-y-1 text-xs text-charcoal/70">
                                <li class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 rounded-full bg-primary" aria-hidden="true"></span>
                                    {{ __('attendance.status.present') }} ({{ $pct($donutRate) }})
                                </li>
                                <li class="flex items-center gap-1.5">
                                    <span class="h-2 w-2 rounded-full bg-heritage-red" aria-hidden="true"></span>
                                    {{ __('attendance.legend_not_present') }}
                                </li>
                            </ul>
                        </div>
                    @endif
                </div>

                <div class="rounded border border-sand bg-white p-4">
                    <h2 class="text-sm font-semibold text-charcoal">
                        {{ __('attendance.calendar_title') }} — {{ $today->isoFormat('MMMM YYYY') }}
                    </h2>

                    @if ($calendarDays === [])
                        <p class="mt-2 text-xs text-charcoal/60">{{ __('attendance.no_calendar') }}</p>
                    @else
                        @php
                            // Resolve section-specific over the 0 sentinel per date.
                            $byDate = [];
                            foreach ($calendarDays as $calDay) {
                                $key = (string) $calDay->date;
                                if (! isset($byDate[$key]) || (int) $calDay->school_section_id !== 0) {
                                    $byDate[$key] = (string) $calDay->day_type;
                                }
                            }
                            $first = $today->copy()->startOfMonth();
                            $pad = $first->dayOfWeekIso - 1;
                        @endphp
                        <div class="mt-2 grid grid-cols-7 gap-1 text-center text-xs">
                            @foreach ([__('attendance.dow_mo'), __('attendance.dow_tu'), __('attendance.dow_we'), __('attendance.dow_th'), __('attendance.dow_fr'), __('attendance.dow_sa'), __('attendance.dow_su')] as $dow)
                                <span class="font-medium text-charcoal/50">{{ $dow }}</span>
                            @endforeach

                            @for ($i = 0; $i < $pad; $i++)
                                <span aria-hidden="true"></span>
                            @endfor

                            @for ($d = 1; $d <= $today->daysInMonth; $d++)
                                @php
                                    $dateKey = $first->copy()->addDays($d - 1)->toDateString();
                                    $type = $byDate[$dateKey] ?? null;
                                    $tone = match ($type) {
                                        'teaching', 'exam' => 'bg-primary/10 text-primary',
                                        'public_holiday', 'school_holiday', 'closure' => 'bg-heritage-red/10 text-heritage-red',
                                        'staff_day' => 'bg-heritage-yellow/20 text-charcoal',
                                        default => 'text-charcoal/50',
                                    };
                                @endphp
                                <span class="rounded px-0.5 py-1 {{ $tone }} {{ $dateKey === $today->toDateString() ? 'ring-1 ring-primary font-semibold' : '' }}">
                                    {{ $d }}
                                </span>
                            @endfor
                        </div>
                        <ul class="mt-3 flex flex-wrap gap-3 text-xs text-charcoal/70">
                            <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-primary" aria-hidden="true"></span>{{ __('attendance.legend_school_days') }}</li>
                            <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-heritage-red" aria-hidden="true"></span>{{ __('attendance.legend_holidays') }}</li>
                            <li class="flex items-center gap-1.5"><span class="h-2 w-2 rounded-full bg-heritage-yellow" aria-hidden="true"></span>{{ __('attendance.legend_events') }}</li>
                        </ul>
                    @endif
                </div>
            </div>
        </div>
    @endif
</div>
