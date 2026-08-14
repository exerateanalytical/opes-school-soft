@php
    $typeLabel = [
        'club' => 'Club',
        'sport' => 'Sport',
        'event' => 'Event',
        'excursion' => 'Excursion',
    ];

    $roleLabel = [
        'member' => 'Member',
        'captain' => 'Captain',
        'leader' => 'Leader',
    ];

    $consentTone = [
        'pending' => 'amber',
        'granted' => 'ok',
        'declined' => 'red',
    ];

    $consentLabel = [
        'pending' => 'Pending',
        'granted' => 'Granted',
        'declined' => 'Declined',
    ];

    $attendanceLabel = [
        'present' => 'Present',
        'absent' => 'Absent',
        'excused' => 'Excused',
    ];

    $tabs = [
        ['value' => 'members', 'label' => 'Members'],
        ['value' => 'sessions', 'label' => 'Sessions'],
        ['value' => 'attendance', 'label' => 'Attendance'],
    ];

    if ($isExcursion) {
        $tabs[] = ['value' => 'consent', 'label' => 'Consent'];
    }
@endphp

<div class="min-w-0 space-y-4">
    {{-- Breadcrumb --}}
    <nav aria-label="Breadcrumb" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li><a href="{{ url('/activities') }}" class="hover:text-primary hover:underline">Activities</a></li>
            <li aria-hidden="true" class="text-charcoal/30">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $activity->name }}</li>
        </ol>
    </nav>

    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Header --}}
    <div class="flex flex-wrap items-start justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-2xl font-bold tracking-tight text-charcoal">{{ $activity->name }}</h1>
            <p class="mt-1 flex flex-wrap items-center gap-2 text-sm text-charcoal/70">
                <x-status-pill :status="$activity->type === 'excursion' ? 'amber' : 'ok'" :label="$typeLabel[$activity->type] ?? $activity->type"/>
                <x-status-pill :status="$activity->status === 'active' ? 'ok' : 'amber'" :label="$activity->status === 'active' ? 'Active' : 'Closed'"/>
                @if ($activity->venue !== null)
                    <span>{{ $activity->venue }}</span>
                @endif
            </p>
        </div>

        <div class="flex flex-wrap items-center gap-2">
            <a href="{{ url('/activities') }}"
               class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                Back to activities
            </a>
            @if ($canManage && $activity->status === 'active')
                <button type="button" wire:click="closeActivity"
                        wire:confirm="Close this activity? Every active membership will be ended."
                        class="rounded border border-heritage-red px-4 py-2 text-sm font-semibold text-heritage-red hover:bg-heritage-red/10">
                    Close activity
                </button>
            @endif
        </div>
    </div>

    {{-- Excursion trip facts --}}
    @if ($isExcursion)
        <section aria-label="Excursion details" class="rounded-xl border border-border-primary bg-white p-4">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Excursion</h2>
            <dl class="mt-2 grid grid-cols-1 gap-x-8 gap-y-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs text-charcoal/60">Destination</dt>
                    <dd class="font-medium text-charcoal">{{ $activity->destination ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/60">Departure</dt>
                    <dd class="font-medium text-charcoal">{{ $activity->departure_at ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/60">Return</dt>
                    <dd class="font-medium text-charcoal">{{ $activity->return_at ?? '—' }}</dd>
                </div>
            </dl>
        </section>
    @endif

    {{-- KPI strip --}}
    <div class="grid grid-cols-2 gap-3 md:grid-cols-4">
        <x-kpi-card label="Active Members" :value="$stats['members']" tone="green"/>
        <x-kpi-card label="Sessions" :value="$stats['sessions']" tone="blue"/>
        <x-kpi-card label="Attendance Rate" :value="$stats['sessions'] > 0 ? $stats['attendance_rate'].'%' : null" tone="amber"/>
        @if ($isExcursion)
            <x-kpi-card label="Pending Consents" :value="$stats['pending_consents']" tone="pink"/>
        @else
            <x-kpi-card label="Capacity" :value="$activity->capacity !== null ? $stats['members'].' / '.$activity->capacity : null" tone="purple"/>
        @endif
    </div>

    {{-- Tabs --}}
    <div class="-mx-4 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:px-0">
        <div class="flex min-w-max items-center gap-1">
            @foreach ($tabs as $tabOption)
                <button type="button" wire:click="selectTab('{{ $tabOption['value'] }}')"
                        @if ($tab === $tabOption['value']) aria-current="page" @endif
                        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $tabOption['value']
                            ? 'border-primary font-semibold text-primary'
                            : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $tabOption['label'] }}
                </button>
            @endforeach
        </div>
    </div>

    {{-- ── Members tab ─────────────────────────────────────────────────── --}}
    @if ($tab === 'members')
        @if ($canManage && $activity->status === 'active')
            <div>
                <button type="button" wire:click="toggleEnrolForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showEnrolForm ? 'Hide form' : 'Enrol student' }}
                </button>
            </div>

            @if ($showEnrolForm)
                <section aria-label="Enrol student" class="rounded-lg border border-border-primary bg-white p-4">
                    <h2 class="text-base font-semibold text-charcoal">Enrol Student</h2>

                    <form wire:submit="enrolStudent" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <label for="enrol-form-student" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Student ID</span>
                            <input id="enrol-form-student" type="number" min="1" wire:model="enrolFormStudentId"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('enrolFormStudentId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="enrol-form-role" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Role</span>
                            <select id="enrol-form-role" wire:model="enrolFormRole"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal">
                                <option value="member">Member</option>
                                <option value="captain">Captain</option>
                                <option value="leader">Leader</option>
                            </select>
                            @error('enrolFormRole')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="enrol-form-starts-on" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Starts on</span>
                            <input id="enrol-form-starts-on" type="date" wire:model="enrolFormStartsOn"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('enrolFormStartsOn')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="sm:col-span-3">
                            <button type="submit"
                                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                                Enrol
                            </button>
                        </div>
                    </form>
                </section>
            @endif
        @endif

        @if ($members->isEmpty())
            <x-empty-state message="No members yet. Enrol the first student to start the register."/>
        @else
            <div class="min-w-0 overflow-hidden rounded-xl border border-border-primary bg-white">
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-border-primary bg-chrome text-left text-white [&_th]:whitespace-nowrap">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Role</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Since</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Until</th>
                                @if ($isExcursion)
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Consent</th>
                                @endif
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-secondary">
                            @foreach ($members as $member)
                                <tr wire:key="member-{{ $member->id }}" class="hover:bg-sand/30">
                                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($member->first_name.' '.$member->last_name) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $member->matricule }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $roleLabel[$member->role] ?? $member->role }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $member->starts_on }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $member->ends_on ?? '—' }}</td>
                                    @if ($isExcursion)
                                        <td class="px-4 py-2.5">
                                            @if ($member->consent_status !== null)
                                                <x-status-pill :status="$consentTone[$member->consent_status] ?? 'amber'" :label="$consentLabel[$member->consent_status] ?? $member->consent_status"/>
                                            @else
                                                <span class="text-charcoal/50">—</span>
                                            @endif
                                        </td>
                                    @endif
                                    <td class="px-4 py-2.5">
                                        <x-status-pill :status="$member->status === 'active' ? 'ok' : 'amber'" :label="$member->status === 'active' ? 'Active' : 'Ended'"/>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    {{-- ── Sessions tab ────────────────────────────────────────────────── --}}
    @if ($tab === 'sessions')
        @if ($canManage && $activity->status === 'active')
            <div>
                <button type="button" wire:click="toggleSessionForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showSessionForm ? 'Hide form' : 'Schedule session' }}
                </button>
            </div>

            @if ($showSessionForm)
                <section aria-label="Schedule session" class="rounded-lg border border-border-primary bg-white p-4">
                    <h2 class="text-base font-semibold text-charcoal">Schedule Session</h2>

                    <form wire:submit="scheduleSession" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-3">
                        <label for="session-form-date" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Date</span>
                            <input id="session-form-date" type="date" wire:model="sessionFormDate"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormDate')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="session-form-starts-at" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Starts (HH:MM)</span>
                            <input id="session-form-starts-at" type="time" wire:model="sessionFormStartsAt"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormStartsAt')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="session-form-ends-at" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Ends (HH:MM)</span>
                            <input id="session-form-ends-at" type="time" wire:model="sessionFormEndsAt"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormEndsAt')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="session-form-venue" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Venue</span>
                            <input id="session-form-venue" type="text" wire:model="sessionFormVenue"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormVenue')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="session-form-supervisor" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Supervisor staff ID (optional)</span>
                            <input id="session-form-supervisor" type="number" min="1" wire:model="sessionFormSupervisorId"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormSupervisorId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="session-form-notes" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Notes</span>
                            <input id="session-form-notes" type="text" wire:model="sessionFormNotes"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('sessionFormNotes')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="sm:col-span-3">
                            <button type="submit"
                                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                                Schedule
                            </button>
                        </div>
                    </form>
                </section>
            @endif
        @endif

        @if ($sessions->isEmpty())
            <x-empty-state message="No sessions scheduled yet."/>
        @else
            <div class="min-w-0 overflow-hidden rounded-xl border border-border-primary bg-white">
                <div class="min-w-0 overflow-x-auto">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-border-primary bg-chrome text-left text-white [&_th]:whitespace-nowrap">
                            <tr>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Date</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Time</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Venue</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Supervisor</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Present / Marked</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-secondary">
                            @foreach ($sessions as $session)
                                <tr wire:key="session-{{ $session->id }}" class="hover:bg-sand/30">
                                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ $session->scheduled_on }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">
                                        @if ($session->starts_at !== null)
                                            {{ substr((string) $session->starts_at, 0, 5) }}@if ($session->ends_at !== null) – {{ substr((string) $session->ends_at, 0, 5) }}@endif
                                        @else
                                            —
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $session->venue ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">
                                        {{ $session->supervisor_first_name !== null ? trim($session->supervisor_first_name.' '.$session->supervisor_last_name) : '—' }}
                                    </td>
                                    <td class="px-4 py-2.5 text-right tabular-nums">{{ $session->present_count }} / {{ $session->marked_count }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif
    @endif

    {{-- ── Attendance tab ──────────────────────────────────────────────── --}}
    @if ($tab === 'attendance')
        <section aria-label="Attendance register" class="space-y-3">
            <label for="attendance-session" class="flex max-w-md flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Session</span>
                <select id="attendance-session" wire:model.live="sessionId"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">Choose a session</option>
                    @foreach ($sessionOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
                @error('sessionId')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            @if ($sessionId === '')
                <x-empty-state message="Choose a session to open its register."/>
            @elseif ($registerRows->isEmpty())
                <x-empty-state message="This activity has no active members to mark."/>
            @else
                @error('attendanceMarks')
                    <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm text-heritage-red">{{ $message }}</p>
                @enderror

                <div class="min-w-0 overflow-hidden rounded-xl border border-border-primary bg-white">
                    <div class="min-w-0 overflow-x-auto">
                        <table class="w-full min-w-[36rem] border-collapse text-sm">
                            <thead class="border-b border-border-primary bg-chrome text-left text-white [&_th]:whitespace-nowrap">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Recorded</th>
                                    @if ($canManage)
                                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Mark</th>
                                    @endif
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-secondary">
                                @foreach ($registerRows as $row)
                                    <tr wire:key="register-{{ $sessionId }}-{{ $row->id }}" class="hover:bg-sand/30">
                                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->recorded_status !== null ? ($attendanceLabel[$row->recorded_status] ?? $row->recorded_status) : '—' }}</td>
                                        @if ($canManage)
                                            <td class="px-4 py-2.5">
                                                <select wire:model="attendanceMarks.{{ $row->id }}"
                                                        aria-label="Mark for {{ trim($row->first_name.' '.$row->last_name) }}"
                                                        class="rounded border border-border-primary bg-white px-2 py-1 text-sm text-charcoal">
                                                    <option value="">—</option>
                                                    <option value="present">Present</option>
                                                    <option value="absent">Absent</option>
                                                    <option value="excused">Excused</option>
                                                </select>
                                            </td>
                                        @endif
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>

                @if ($canManage)
                    <button type="button" wire:click="saveAttendance"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save register
                    </button>
                @endif
            @endif
        </section>
    @endif

    {{-- ── Consent tab (excursions only) ───────────────────────────────── --}}
    @if ($tab === 'consent' && $isExcursion)
        <section aria-label="Guardian consent" class="space-y-3">
            @if ($canManage && $activity->status === 'active')
                <div class="rounded-lg border border-border-primary bg-white p-4">
                    <h2 class="text-base font-semibold text-charcoal">Record Consent</h2>
                    <p class="mt-1 text-xs text-charcoal/60">
                        Only a guardian currently linked to the student can consent for them.
                    </p>

                    <form wire:submit="recordConsent" class="mt-3 grid grid-cols-1 gap-4 sm:grid-cols-4">
                        <label for="consent-form-membership" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Member</span>
                            <select id="consent-form-membership" wire:model.live="consentFormMembershipId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal">
                                <option value="">Select a member</option>
                                @foreach ($consentRows as $row)
                                    <option value="{{ $row->id }}">{{ trim($row->first_name.' '.$row->last_name) }} ({{ $row->matricule }})</option>
                                @endforeach
                            </select>
                            @error('consentFormMembershipId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="consent-form-guardian" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Guardian</span>
                            <select id="consent-form-guardian" wire:model="consentFormGuardianId"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal">
                                <option value="">Select a guardian</option>
                                @foreach ($guardianOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                            @error('consentFormGuardianId')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="consent-form-decision" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Decision</span>
                            <select id="consent-form-decision" wire:model="consentFormDecision"
                                    class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal">
                                <option value="granted">Granted</option>
                                <option value="declined">Declined</option>
                            </select>
                            @error('consentFormDecision')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="consent-form-note" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Note (optional)</span>
                            <input id="consent-form-note" type="text" wire:model="consentFormNote"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal"/>
                            @error('consentFormNote')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <div class="sm:col-span-4">
                            <button type="submit"
                                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                                Record consent
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            @if ($consentRows->isEmpty())
                <x-empty-state message="No active members on this excursion yet - consent appears here once students are enrolled."/>
            @else
                <div class="min-w-0 overflow-hidden rounded-xl border border-border-primary bg-white">
                    <div class="min-w-0 overflow-x-auto">
                        <table class="w-full min-w-[40rem] border-collapse text-sm">
                            <thead class="border-b border-border-primary bg-chrome text-left text-white [&_th]:whitespace-nowrap">
                                <tr>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Consent</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Decided By</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Recorded At</th>
                                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Note</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-secondary">
                                @foreach ($consentRows as $row)
                                    <tr wire:key="consent-{{ $row->id }}" class="hover:bg-sand/30">
                                        <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->matricule }}</td>
                                        <td class="px-4 py-2.5">
                                            <x-status-pill :status="$consentTone[$row->consent_status ?? 'pending'] ?? 'amber'"
                                                           :label="$consentLabel[$row->consent_status ?? 'pending'] ?? 'Pending'"/>
                                        </td>
                                        <td class="px-4 py-2.5 text-charcoal/80">
                                            {{ $row->guardian_first_name !== null ? trim($row->guardian_first_name.' '.$row->guardian_last_name) : '—' }}
                                        </td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->consent_recorded_at ?? '—' }}</td>
                                        <td class="px-4 py-2.5 text-charcoal/80">{{ $row->consent_note ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif
        </section>
    @endif
</div>
