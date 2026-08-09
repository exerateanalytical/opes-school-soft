{{-- Register coverage — 07-students §9.6: registers taken ÷ teaching days,
     per class group per period. A FIRST-CLASS screen, because the class
     whose teacher takes no registers is exactly the C5 failure mode this
     module exists to surface. --}}

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('attendance.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li>{{ __('attendance.breadcrumb_attendance') }}</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ __('attendance.coverage_title') }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">{{ __('attendance.coverage_title') }}</h1>

        @if ($periods->isNotEmpty())
            <label class="block text-xs font-medium text-charcoal/70">
                {{ __('attendance.filter_period') }}
                <select wire:model.live="periodId"
                        class="mt-1 rounded border-sand text-sm focus:border-primary focus:ring-primary">
                    @foreach ($periods as $periodOption)
                        <option value="{{ $periodOption->id }}">{{ $periodOption->name }}</option>
                    @endforeach
                </select>
            </label>
        @endif
    </div>

    @if (! $hasYear)
        <x-empty-state :message="__('attendance.no_year')"/>
    @elseif ($period === null)
        <x-empty-state :message="__('attendance.no_periods')"/>
    @else
        <p class="text-xs text-charcoal/60">
            {{ __('attendance.coverage_explainer', ['from' => $period->starts_on, 'to' => $period->ends_on]) }}
        </p>

        <div class="rounded border border-sand bg-white">
            @if ($rows === [])
                <div class="px-4 py-6">
                    <x-empty-state :message="__('attendance.no_class_groups')"/>
                </div>
            @else
                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-sand text-sm">
                        <thead class="bg-cream text-left text-xs uppercase tracking-wide text-charcoal/60">
                            <tr>
                                <th scope="col" class="px-4 py-2">{{ __('attendance.col_class') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('attendance.col_mode') }}</th>
                                <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.col_teaching_days') }}</th>
                                <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.col_days_taken') }}</th>
                                <th scope="col" class="px-4 py-2 text-right">{{ __('attendance.col_coverage') }}</th>
                                <th scope="col" class="px-4 py-2">{{ __('attendance.col_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand bg-white">
                            @foreach ($rows as $row)
                                @php
                                    $coverage = $row['coverage'];
                                    $pillStatus = $coverage === null
                                        ? 'amber'
                                        : ($coverage >= 0.9 ? 'ok' : ($coverage >= 0.5 ? 'amber' : 'red'));
                                    $pillLabel = $coverage === null
                                        ? __('attendance.coverage_no_calendar')
                                        : ($coverage >= 0.9
                                            ? __('attendance.coverage_ok')
                                            : ($coverage >= 0.5
                                                ? __('attendance.coverage_partial')
                                                : __('attendance.coverage_poor')));
                                @endphp
                                <tr wire:key="coverage-{{ $row['class_group_id'] }}">
                                    <td class="px-4 py-2 font-medium text-charcoal">{{ $row['name'] }}</td>
                                    <td class="px-4 py-2 text-charcoal/70">{{ __('attendance.mode.'.$row['mode']) }}</td>
                                    <td class="px-4 py-2 text-right">{{ $row['teaching_days'] }}</td>
                                    <td class="px-4 py-2 text-right">{{ $row['days_taken'] }}</td>
                                    <td class="px-4 py-2 text-right font-medium">
                                        @if ($coverage === null)
                                            <span title="{{ __('opes.ui.no_data') }}">—</span>
                                        @else
                                            {{ number_format($coverage * 100, 1) }}%
                                        @endif
                                    </td>
                                    <td class="px-4 py-2">
                                        <x-status-pill :status="$pillStatus" :label="$pillLabel"/>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>
    @endif
</div>
