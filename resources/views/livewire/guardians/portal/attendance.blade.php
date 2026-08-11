<div class="min-w-0 space-y-4">
    @include('livewire.guardians.portal._child-tabs', [
        'studentId' => $studentId,
        'childName' => $childName,
        'active' => 'attendance',
    ])

    <section aria-labelledby="portal-attendance-summary" class="rounded border border-border-primary bg-white p-4 shadow-sm">
        <h2 id="portal-attendance-summary" class="text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.attendance_summary') }}
        </h2>

        @if ($summaries->isEmpty())
            <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.attendance_empty') }}</p>
        @else
            {{-- Cards on a phone, a table from `sm` up. A five-column table on a
                 360px screen is unreadable, and this is the screen a parent is
                 most likely to open standing at a school gate. --}}
            <div class="mt-3 space-y-3 sm:hidden">
                @foreach ($summaries as $summary)
                    <div wire:key="att-card-{{ $loop->index }}" class="rounded border border-border-secondary p-3">
                        <p class="text-sm font-semibold text-charcoal">
                            {{ app()->getLocale() === 'fr' && $summary->period_name_fr ? $summary->period_name_fr : $summary->period_name }}
                        </p>
                        <dl class="mt-2 grid grid-cols-2 gap-x-3 gap-y-1 text-xs">
                            <dt class="text-charcoal/60">{{ __('opes.guardian_portal.attendance_present') }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $summary->sessions_present }}</dd>
                            <dt class="text-charcoal/60">{{ __('opes.guardian_portal.attendance_absent') }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $summary->sessions_absent }}</dd>
                            <dt class="text-charcoal/60">{{ __('opes.guardian_portal.attendance_late') }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $summary->sessions_late }}</dd>
                            <dt class="text-charcoal/60">{{ __('opes.guardian_portal.attendance_expected') }}</dt>
                            <dd class="text-right font-medium text-charcoal">{{ $summary->sessions_expected }}</dd>
                        </dl>
                    </div>
                @endforeach
            </div>

            <div class="mt-3 hidden overflow-x-auto sm:block">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="border-b border-border-primary text-left text-xs uppercase tracking-wide text-charcoal/60">
                            <th scope="col" class="py-2 pr-3 font-medium">{{ __('opes.guardian_portal.attendance_period') }}</th>
                            <th scope="col" class="py-2 px-3 text-right font-medium">{{ __('opes.guardian_portal.attendance_expected') }}</th>
                            <th scope="col" class="py-2 px-3 text-right font-medium">{{ __('opes.guardian_portal.attendance_present') }}</th>
                            <th scope="col" class="py-2 px-3 text-right font-medium">{{ __('opes.guardian_portal.attendance_absent') }}</th>
                            <th scope="col" class="py-2 px-3 text-right font-medium">{{ __('opes.guardian_portal.attendance_excused') }}</th>
                            <th scope="col" class="py-2 pl-3 text-right font-medium">{{ __('opes.guardian_portal.attendance_late') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-secondary">
                        @foreach ($summaries as $summary)
                            <tr wire:key="att-row-{{ $loop->index }}">
                                <td class="py-2 pr-3 text-charcoal">
                                    {{ app()->getLocale() === 'fr' && $summary->period_name_fr ? $summary->period_name_fr : $summary->period_name }}
                                </td>
                                <td class="py-2 px-3 text-right tabular-nums text-charcoal/80">{{ $summary->sessions_expected }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-success">{{ $summary->sessions_present }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-danger">{{ $summary->sessions_absent }}</td>
                                <td class="py-2 px-3 text-right tabular-nums text-charcoal/80">{{ $summary->sessions_excused }}</td>
                                <td class="py-2 pl-3 text-right tabular-nums text-warning">{{ $summary->sessions_late }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if (! $canDetail)
        {{-- Said plainly. An empty session table would read as "your child has
             never been marked", which is a very different and much more
             alarming claim than "your school shares totals with you". --}}
        <p class="rounded border border-border-secondary bg-surface-green px-4 py-3 text-sm text-charcoal/70">
            {{ __('opes.guardian_portal.attendance_summary_only') }}
        </p>
    @else
        <section aria-labelledby="portal-attendance-detail" class="rounded border border-border-primary bg-white p-4 shadow-sm">
            <h2 id="portal-attendance-detail" class="text-sm font-semibold text-charcoal">
                {{ __('opes.guardian_portal.attendance_detail') }}
            </h2>

            @if ($records->isEmpty())
                <p class="mt-3 text-sm text-charcoal/60">{{ __('opes.guardian_portal.attendance_empty') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-border-primary text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <th scope="col" class="py-2 pr-3 font-medium">{{ __('opes.guardian_portal.attendance_date') }}</th>
                                <th scope="col" class="py-2 px-3 font-medium">{{ __('opes.guardian_portal.attendance_session') }}</th>
                                <th scope="col" class="py-2 px-3 font-medium">{{ __('opes.guardian_portal.attendance_status') }}</th>
                                <th scope="col" class="py-2 pl-3 font-medium">{{ __('opes.guardian_portal.attendance_justified') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-secondary">
                            @foreach ($records as $record)
                                <tr wire:key="att-rec-{{ $loop->index }}">
                                    <td class="py-2 pr-3 whitespace-nowrap text-charcoal">{{ $record->session_date }}</td>
                                    <td class="py-2 px-3 text-charcoal/70">{{ $record->session }}</td>
                                    <td class="py-2 px-3">
                                        <span @class([
                                            'rounded px-2 py-0.5 text-xs font-medium',
                                            'bg-success-bg text-success' => $record->status === 'present',
                                            'bg-danger-bg text-danger' => $record->status === 'absent',
                                            'bg-warning-bg text-warning' => ! in_array($record->status, ['present', 'absent'], true),
                                        ])>{{ $record->status }}</span>
                                    </td>
                                    <td class="py-2 pl-3 text-charcoal/70">
                                        {{ $record->is_justified ? __('opes.guardian_portal.attendance_justified') : '—' }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
