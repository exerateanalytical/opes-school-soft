@php
    use App\Modules\Admissions\Domain\ApplicationStatus;

    // Literal labels, not translation keys: lang/en|fr/opes.php is being
    // edited concurrently by another build and must not be touched here.
    $statusLabels = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'under_review' => 'Under review',
        'accepted' => 'Accepted',
        'rejected' => 'Rejected',
        'enrolled' => 'Enrolled',
        'expired' => 'Expired',
        'withdrawn' => 'Withdrawn',
    ];

    $statusTone = [
        'draft' => 'amber',
        'submitted' => 'amber',
        'under_review' => 'amber',
        'accepted' => 'ok',
        'enrolled' => 'ok',
        'rejected' => 'red',
        'expired' => 'red',
        'withdrawn' => 'red',
    ];

    $tabs = [['value' => 'all', 'label' => 'All']];

    foreach (ApplicationStatus::cases() as $case) {
        $tabs[] = ['value' => $case->value, 'label' => $statusLabels[$case->value]];
    }
@endphp

{{-- Single root element: Livewire 4 requires exactly one. --}}
<div class="min-w-0 space-y-4">

    @if ($statusMessage !== '')
        <p role="status"
           class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary">
            {{ $statusMessage }}
        </p>
    @endif

    {{-- ── Reject dialog ────────────────────────────────────────────────── --}}
    @if ($rejectingId !== null)
        <section class="rounded border border-heritage-red/40 bg-heritage-red/5 p-3"
                 aria-label="Reject application">
            <h2 class="text-sm font-semibold text-charcoal">Reject application #{{ $rejectingId }}</h2>
            <p class="mt-1 text-xs text-charcoal/70">
                The reason is shown to the family and is the only part of the record that survives
                the 12-month retention purge. It is required.
            </p>
            <label for="admissions-reject-reason" class="mt-2 flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Reason</span>
                <textarea id="admissions-reject-reason" rows="2" wire:model="rejectionReason"
                          class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
            </label>
            @error('decision_reason')
                <p class="mt-1 text-xs font-medium text-heritage-red">{{ $message }}</p>
            @enderror
            <div class="mt-2 flex items-center gap-2">
                <button type="button" wire:click="reject"
                        class="rounded bg-heritage-red px-3 py-1.5 text-sm font-semibold text-white hover:bg-heritage-red/90">
                    Confirm rejection
                </button>
                <button type="button" wire:click="closeDialogs"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50">
                    Cancel
                </button>
            </div>
        </section>
    @endif

    {{-- ── Convert dialog ───────────────────────────────────────────────── --}}
    @if ($convertingId !== null)
        <section class="rounded border border-primary/40 bg-primary/5 p-3"
                 aria-label="Convert application to student">
            <h2 class="text-sm font-semibold text-charcoal">Convert application #{{ $convertingId }} to a student</h2>
            <p class="mt-1 text-xs text-charcoal/70">
                The application named a class LEVEL; the class GROUP is chosen here, at enrolment,
                and capacity is checked under lock by the enrolment action itself.
            </p>

            @if ($classGroupOptions === [])
                <p class="mt-2 text-sm font-medium text-heritage-red">
                    No class group exists for this application's academic year and level. Create one first.
                </p>
            @else
                <label for="admissions-class-group" class="mt-2 flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Class group</span>
                    <select id="admissions-class-group" wire:model="classGroupId"
                            class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                        <option value="">Select a class group</option>
                        @foreach ($classGroupOptions as $option)
                            <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                        @endforeach
                    </select>
                </label>
            @endif

            @error('classGroupId')
                <p class="mt-1 text-xs font-medium text-heritage-red">{{ $message }}</p>
            @enderror

            <div class="mt-2 flex items-center gap-2">
                <button type="button" wire:click="convert"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Confirm enrolment
                </button>
                <button type="button" wire:click="closeDialogs"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50">
                    Cancel
                </button>
            </div>
        </section>
    @endif

    <x-list-screen
        title="Admission Applications"
        :breadcrumb="['Students', 'Admissions']"
        :paginator="$rows"
        empty-message="No applications in this queue yet. Start one with New application."
    >
        <x-slot:actions>
            <a href="{{ $newDraftUrl }}"
               class="print:hidden rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                New application
            </a>
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="All applications" :value="$kpis['total']"/>
            <x-kpi-card label="Drafts" :value="$kpis['drafts']"/>
            <x-kpi-card label="Awaiting decision" :value="$kpis['awaiting_decision']"/>
            <x-kpi-card label="Accepted" :value="$kpis['accepted']"/>
            <x-kpi-card label="Enrolled" :value="$kpis['enrolled']"/>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="admissions-search" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="admissions-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Application no or name"
                       class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
            </label>

            <label for="admissions-year" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                <select id="admissions-year" wire:model.live="academicYear"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All years</option>
                    @foreach ($academicYearOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="admissions-level" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Class level</span>
                <select id="admissions-level" wire:model.live="classLevel"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All levels</option>
                    @foreach ($classLevelOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        </x-slot:filters>

        <x-slot:tabs>
            @foreach ($tabs as $t)
                <button type="button" wire:click="selectTab('{{ $t['value'] }}')"
                        class="print:hidden whitespace-nowrap border-b-2 px-3 py-2 text-sm font-medium
                               {{ $tab === $t['value'] ? 'border-primary text-primary' : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ $t['label'] }}
                    <span class="ml-1 text-xs text-charcoal/50">({{ $tabCounts[$t['value']] ?? 0 }})</span>
                </button>
            @endforeach
        </x-slot:tabs>

        <x-slot:head>
            <tr>
                <th class="px-3 py-2 font-medium text-charcoal/70">Application No</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Applicant</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Year</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Level / Section</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Status</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Progress</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Submitted</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Actions</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            @php
                $status = ApplicationStatus::tryFrom((string) $row->status);
                $name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
                $convertible = $status !== null && $status->isConvertible() && $row->converted_student_id === null;
                $decidable = $status !== null && in_array($status, [
                    ApplicationStatus::Submitted,
                    ApplicationStatus::UnderReview,
                    ApplicationStatus::Accepted,
                ], true);
            @endphp
            <tr>
                <td class="px-3 py-2 font-mono text-xs">{{ $row->application_no ?? '—' }}</td>
                <td class="px-3 py-2">{{ $name !== '' ? $name : 'Unnamed draft #'.$row->id }}</td>
                <td class="px-3 py-2">{{ $row->academic_year_code ?? '—' }}</td>
                <td class="px-3 py-2">{{ $row->class_level_name ?? '—' }}{{ $row->section_name ? ' · '.$row->section_name : '' }}</td>
                <td class="px-3 py-2">
                    <x-status-pill :status="$statusTone[(string) $row->status] ?? 'amber'"
                                   :label="$statusLabels[(string) $row->status] ?? (string) $row->status"/>
                </td>
                <td class="px-3 py-2 text-xs text-charcoal/70">Step {{ $row->current_step }} of 5 · {{ $row->completed_step }} complete</td>
                <td class="whitespace-nowrap px-3 py-2">{{ $row->submitted_at ?? '—' }}</td>
                <td class="px-3 py-2">
                    <div class="flex flex-wrap items-center gap-1">
                        <a href="{{ $this->wizardUrl((int) $row->id) }}"
                           class="print:hidden rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ $status === ApplicationStatus::Draft ? 'Continue' : 'Open' }}
                        </a>
                        @if ($convertible)
                            <button type="button" wire:click="startConvert({{ $row->id }})"
                                    class="print:hidden rounded border border-primary px-2 py-1 text-xs font-semibold text-primary hover:bg-primary/10">
                                Convert
                            </button>
                        @endif
                        @if ($decidable)
                            <button type="button" wire:click="startReject({{ $row->id }})"
                                    class="print:hidden rounded border border-heritage-red/50 px-2 py-1 text-xs font-semibold text-heritage-red hover:bg-heritage-red/10">
                                Reject
                            </button>
                        @endif
                    </div>
                    @if ($row->decision_reason)
                        <p class="mt-1 text-xs text-charcoal/60">Reason: {{ $row->decision_reason }}</p>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:cards>
            @foreach ($rows as $row)
                @php
                    $status = ApplicationStatus::tryFrom((string) $row->status);
                    $name = trim(implode(' ', array_filter([$row->first_name, $row->middle_name, $row->last_name])));
                    $convertible = $status !== null && $status->isConvertible() && $row->converted_student_id === null;
                    $decidable = $status !== null && in_array($status, [
                        ApplicationStatus::Submitted,
                        ApplicationStatus::UnderReview,
                        ApplicationStatus::Accepted,
                    ], true);
                @endphp
                <article class="rounded border border-sand bg-white p-3">
                    <p class="font-medium text-charcoal">{{ $name !== '' ? $name : 'Unnamed draft #'.$row->id }}</p>
                    <p class="text-sm text-charcoal/70">
                        {{ $row->application_no ?? 'No number yet' }} · {{ $row->class_level_name ?? '—' }} · {{ $row->academic_year_code ?? '—' }}
                    </p>
                    <p class="mt-1">
                        <x-status-pill :status="$statusTone[(string) $row->status] ?? 'amber'"
                                       :label="$statusLabels[(string) $row->status] ?? (string) $row->status"/>
                    </p>
                    <div class="mt-2 flex flex-wrap items-center gap-1">
                        <a href="{{ $this->wizardUrl((int) $row->id) }}"
                           class="print:hidden rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ $status === ApplicationStatus::Draft ? 'Continue' : 'Open' }}
                        </a>
                        @if ($convertible)
                            <button type="button" wire:click="startConvert({{ $row->id }})"
                                    class="print:hidden rounded border border-primary px-2 py-1 text-xs font-semibold text-primary hover:bg-primary/10">
                                Convert
                            </button>
                        @endif
                        @if ($decidable)
                            <button type="button" wire:click="startReject({{ $row->id }})"
                                    class="print:hidden rounded border border-heritage-red/50 px-2 py-1 text-xs font-semibold text-heritage-red hover:bg-heritage-red/10">
                                Reject
                            </button>
                        @endif
                    </div>
                </article>
            @endforeach
        </x-slot:cards>
    </x-list-screen>
</div>
