@php
    $examTone = [
        'planned' => 'amber',
        'scheduled' => 'ok',
        'in_progress' => 'ok',
        'marked' => 'ok',
        'cancelled' => 'red',
    ];

    $examLabel = [
        'planned' => 'Planned',
        'scheduled' => 'Scheduled',
        'in_progress' => 'In progress',
        'marked' => 'Marked',
        'cancelled' => 'Cancelled',
    ];

    $roleLabel = [
        'chief' => 'Chief',
        'assistant' => 'Assistant',
    ];

    $tabs = [
        ['value' => 'exams', 'label' => 'Exams', 'count' => $tabCounts['exams']],
        ['value' => 'invigilators', 'label' => 'Invigilators', 'count' => $tabCounts['invigilators']],
        ['value' => 'seating', 'label' => 'Seating', 'count' => $tabCounts['seating']],
    ];
@endphp

@if (session('status'))
    <p class="mb-4 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
        {{ session('status') }}
    </p>
@endif

@if (session('error'))
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ session('error') }}
    </p>
@endif

{{-- Inline schedule-exam panel. --}}
@if ($showScheduleForm)
    <section aria-label="Schedule exam" class="mb-4 rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Schedule Exam</h2>

        <form wire:submit="saveSchedule" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="schedule-form-exam-type" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Exam type ID</span>
                    <input id="schedule-form-exam-type" type="number" wire:model="formExamTypeId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formExamTypeId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-period" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Assessment period ID</span>
                    <input id="schedule-form-period" type="number" wire:model="formAssessmentPeriodId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formAssessmentPeriodId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-allocation" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Subject allocation ID</span>
                    <input id="schedule-form-allocation" type="number" wire:model="formSubjectAllocationId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formSubjectAllocationId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-class-group" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Class group ID</span>
                    <input id="schedule-form-class-group" type="number" wire:model="formClassGroupId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formClassGroupId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-date" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Date</span>
                    <input id="schedule-form-date" type="date" wire:model="formScheduledOn"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formScheduledOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-time" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Start time (HH:MM)</span>
                    <input id="schedule-form-time" type="time" wire:model="formStartsAt"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formStartsAt')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-duration" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Duration (minutes)</span>
                    <input id="schedule-form-duration" type="number" wire:model="formDurationMinutes"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formDurationMinutes')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-max-score" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Max score</span>
                    <input id="schedule-form-max-score" type="text" wire:model="formMaxScore"
                           placeholder="e.g. 20"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formMaxScore')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="schedule-form-room" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Room ID (optional)</span>
                    <input id="schedule-form-room" type="number" wire:model="formRoomId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('formRoomId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Schedule exam
                </button>
                <button type="button" wire:click="toggleScheduleForm"
                        class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

{{-- Inline assign-invigilator panel. --}}
@if ($showInvigilatorForm)
    <section aria-label="Assign invigilator" class="mb-4 rounded-lg border border-sand bg-white p-4 shadow-sm sm:p-5">
        <h2 class="text-base font-semibold text-charcoal">Assign Invigilator</h2>

        <form wire:submit="saveInvigilator" class="mt-4 space-y-4">
            <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                <label for="invigilator-form-exam" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Exam</span>
                    <select id="invigilator-form-exam" wire:model="invExamId"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select an exam...</option>
                        @foreach ($examOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                        @endforeach
                    </select>
                    @error('invExamId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="invigilator-form-staff" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Staff member ID</span>
                    <input id="invigilator-form-staff" type="number" wire:model="invStaffId"
                           class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('invStaffId')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="invigilator-form-role" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Role</span>
                    <select id="invigilator-form-role" wire:model="invRole"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="chief">Chief</option>
                        <option value="assistant">Assistant</option>
                    </select>
                    @error('invRole')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>
            </div>

            <div class="flex items-center gap-3">
                <button type="submit"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Assign invigilator
                </button>
                <button type="button" wire:click="toggleInvigilatorForm"
                        class="rounded border border-sand px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                    Cancel
                </button>
            </div>
        </form>
    </section>
@endif

<x-list-screen
    title="Examinations"
    :breadcrumb="['Dashboard', 'Examinations']"
    :paginator="$rows"
    empty-message="No exams match these filters yet. Scheduled sittings, invigilator assignments and seating plans appear here as they are created."
    rail-title="Examinations Overview"
>
    <x-slot:actions>
        <button type="button" wire:click="toggleScheduleForm"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
            {{ $showScheduleForm ? 'Hide form' : 'Schedule exam' }}
        </button>
        @if ($tab === 'invigilators')
            <button type="button" wire:click="toggleInvigilatorForm"
                    class="ml-2 rounded border border-primary px-4 py-2 text-sm font-semibold text-primary hover:bg-primary/10">
                {{ $showInvigilatorForm ? 'Hide form' : 'Assign invigilator' }}
            </button>
        @endif
    </x-slot:actions>

    <x-slot:kpis>
        <x-kpi-card label="Scheduled Exams" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="4" width="16" height="16" rx="2"/><path stroke-linecap="round" d="M4 9h16M8 3v3M16 3v3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Exams This Week" :value="$kpis['this_week']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Unfilled Invigilator Slots" :value="$kpis['unfilled_invigilators']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M17 8v4M17 15h.01"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Seating Not Generated" :value="$kpis['seating_pending']" icon-bg="bg-heritage-red">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="5" width="18" height="14" rx="2"/><path stroke-linecap="round" d="M3 10h18M8 15h.01M12 15h.01M16 15h.01"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($statusOptions !== [])
            <label for="examinations-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="examinations-filter-status" wire:model.live="status"
                        class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="examinations-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="examinations-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search subject, class, room..."
                   class="rounded border border-sand bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:tabs>
        @foreach ($tabs as $tabOption)
            <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                    @if ($tab === $tabOption['value']) aria-current="page" @endif
                    class="flex items-center gap-1.5 whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                        ? 'border-primary font-semibold text-primary'
                        : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                {{ $tabOption['label'] }}
                <span class="rounded-full bg-sand px-1.5 text-xs text-charcoal/70">{{ $tabOption['count'] }}</span>
            </button>
        @endforeach
    </x-slot:tabs>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            @if ($tab === 'exams')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Time</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Seating</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Details</th>
            @elseif ($tab === 'invigilators')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Staff Member</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Role</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Time</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Subject</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Class</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Room</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Seats Filled</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Capacity</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="examinations-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'exams')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->subject_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->scheduled_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ substr((string) $row->starts_at, 0, 5) }} · {{ $row->duration_minutes }}min</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_name ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$examTone[$row->status] ?? 'amber'" :label="$examLabel[$row->status] ?? $row->status"/>
                </td>
                <td class="px-4 py-2.5">
                    @if ($row->has_seating)
                        <x-status-pill status="ok" label="Generated"/>
                    @elseif ($row->room_id !== null && in_array($row->status, \App\Modules\Assessment\Models\Exam::LIVE_STATUSES, true))
                        <button type="button" wire:click="generateSeating({{ $row->id }})"
                                wire:confirm="Generate the seating plan for this sitting?"
                                class="rounded border border-primary px-2.5 py-1 text-xs font-medium text-primary hover:bg-primary/10">
                            Generate seating
                        </button>
                    @else
                        <span class="text-xs text-charcoal/50">—</span>
                    @endif
                </td>
                <td class="px-4 py-2.5">
                    <a href="{{ route('assessment.examinations.show', ['exam' => $row->id]) }}"
                       class="text-sm font-medium text-primary hover:underline">View</a>
                </td>
            @elseif ($tab === 'invigilators')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    <x-status-pill :status="$row->role === 'chief' ? 'amber' : 'ok'" :label="$roleLabel[$row->role] ?? $row->role"/>
                </td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->subject_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->scheduled_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ substr((string) $row->starts_at, 0, 5) }}</td>
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->subject_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->class_group_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->scheduled_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->room_code }} - {{ $row->room_name }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->seats_filled }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->room_capacity }}</td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="examinations-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-sand bg-white p-3">
                @if ($tab === 'exams')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->subject_name }}</p>
                        <x-status-pill :status="$examTone[$row->status] ?? 'amber'" :label="$examLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->class_group_name }} · {{ $row->scheduled_on }} {{ substr((string) $row->starts_at, 0, 5) }} · {{ $row->room_name ?? 'No room' }}</p>
                    <a href="{{ route('assessment.examinations.show', ['exam' => $row->id]) }}"
                       class="mt-2 inline-flex text-sm font-medium text-primary hover:underline">View</a>
                @elseif ($tab === 'invigilators')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$row->role === 'chief' ? 'amber' : 'ok'" :label="$roleLabel[$row->role] ?? $row->role"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->subject_name }} · {{ $row->class_group_name }} · {{ $row->scheduled_on }}</p>
                @else
                    <p class="font-medium text-charcoal">{{ $row->subject_name }} · {{ $row->class_group_name }}</p>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->room_code }} · {{ $row->seats_filled }}/{{ $row->room_capacity }} seats · {{ $row->scheduled_on }}</p>
                @endif
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>
