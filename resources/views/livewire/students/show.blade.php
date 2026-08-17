@php
    use App\Modules\Students\Domain\StudentStatus;
    use App\Modules\Students\Livewire\Students\Show as StudentShow;

    // Same central mapping as the list screen, for the same reason: a status
    // must never read green on one screen and red on the next.
    $statusTone = [
        StudentStatus::Active->value => 'ok',
        StudentStatus::Graduated->value => 'ok',
        StudentStatus::Prospective->value => 'amber',
        StudentStatus::Inactive->value => 'amber',
        StudentStatus::TransferredOut->value => 'red',
        StudentStatus::Withdrawn->value => 'red',
        StudentStatus::Deceased->value => 'red',
    ];

    $initials = mb_strtoupper(mb_substr($student->first_name, 0, 1).mb_substr($student->last_name, 0, 1));

    $verificationTone = [
        'verified' => 'ok',
        'unverified' => 'amber',
        'rejected' => 'red',
    ];

    $severityTone = [
        'low' => 'ok',
        'moderate' => 'amber',
        'high' => 'red',
    ];

    // x-status-pill accepts ok|amber|red ONLY - a domain status passed straight
    // through renders as a green "OK" on every row. Each map below is the
    // domain enum from the owning module's table, checked against
    // information_schema, with the human label supplied separately.
    $attendanceTone = [
        'present' => 'ok',
        'late' => 'amber',
        'excused' => 'amber',
        'sick' => 'amber',
        'suspended' => 'amber',
        'absent' => 'red',
    ];

    $invoiceTone = [
        'issued' => 'amber',
        'draft' => 'amber',
        'cancelled' => 'red',
    ];

    $disciplineTone = [
        'resolved' => 'ok',
        'dismissed' => 'ok',
        'open' => 'red',
        'under_investigation' => 'amber',
    ];
@endphp

{{-- 11.2's five quick actions. None of the five has a screen in Phase 2 -
     enrolment and editing belong to sibling workstreams and neither has a
     route in routes/web.php; the report card, ID card and transfer documents
     are 10-documents. All five render inert with the shell's standard
     "arrives later" title rather than as links to nothing. --}}
@push('sidebar-quick-actions')
    <div class="mx-3 mt-auto rounded-lg border border-heritage-yellow/70 p-3">
        <h2 class="text-xs font-bold uppercase tracking-wide text-heritage-yellow">
            {{ __('opes.dashboard.quick_actions') }}
        </h2>
        <ul class="mt-2 space-y-1">
            @foreach (['Enroll Student', 'Edit Profile', 'Print Report Card', 'Generate ID Card', 'Transfer Student'] as $unbuilt)
                <li>
                    <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
                          class="flex cursor-not-allowed items-center gap-2 rounded px-2 py-1.5 text-sm text-white/40">
                        <span class="h-1.5 w-1.5 shrink-0 rounded-full bg-white/30" aria-hidden="true"></span>
                        {{ $unbuilt }}
                    </span>
                </li>
            @endforeach
        </ul>
    </div>
@endpush

<div class="min-w-0 space-y-4">

    {{-- ── Breadcrumb ─────────────────────────────────────────────────── --}}
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.students_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <a href="{{ route('students.index') }}" class="hover:text-primary">{{ __('opes.students_screen.breadcrumb_students') }}</a>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $student->fullName() }}</span>
            </li>
        </ol>
    </nav>

    @if (session('status'))
        <div class="rounded border border-success/40 bg-success-bg px-3 py-2 text-sm font-medium text-success-text" role="status">
            {{ session('status') }}
        </div>
    @endif

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ __('opes.students_screen.breadcrumb_profile') }}</h1>
        <div class="flex flex-wrap items-center gap-2">
            @if ($canEditStudent)
                <button type="button" wire:click="toggleEditForm"
                        class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    {{ $showEditForm ? 'Cancel edit' : 'Edit profile' }}
                </button>
            @endif
            <a href="{{ route('students.index') }}"
               class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.students_screen.back_to_list') }}
            </a>
        </div>
    </div>

    {{-- ── Edit profile (UpdateStudent) ───────────────────────────────────
         Core identity + contact fields only - the exact allow-list of
         UpdateStudent::EDITABLE that biography edits are permitted to touch;
         matricule, admission_no and status are structurally absent because
         the Action drops them before the model is ever touched. --}}
    <x-opes-modal-form wire-model="showEditForm" :open="$showEditForm" title="Edit profile" max-width="3xl">
            <form wire:submit.prevent="saveEdit" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_first_name">First name</label>
                    <input id="edit_first_name" type="text" wire:model="edit_first_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_first_name') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_middle_name">Middle name</label>
                    <input id="edit_middle_name" type="text" wire:model="edit_middle_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_middle_name') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_last_name">Last name</label>
                    <input id="edit_last_name" type="text" wire:model="edit_last_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_last_name') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_date_of_birth">Date of birth</label>
                    <input id="edit_date_of_birth" type="date" wire:model="edit_date_of_birth"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_date_of_birth') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_gender">Gender</label>
                    <select id="edit_gender" wire:model="edit_gender"
                            class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                        <option value="male">{{ __('opes.students_screen.gender_male') }}</option>
                        <option value="female">{{ __('opes.students_screen.gender_female') }}</option>
                    </select>
                    @error('edit_gender') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_phone">Phone</label>
                    <input id="edit_phone" type="text" wire:model="edit_phone"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_phone') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_email">Email</label>
                    <input id="edit_email" type="email" wire:model="edit_email"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_email') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                @error('showEditForm') <p class="text-xs text-heritage-red sm:col-span-2 lg:col-span-3">{{ $message }}</p> @enderror
                <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-3">
                    <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Save changes
                    </button>
                    <button type="button" wire:click="toggleEditForm" class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
    </x-opes-modal-form>

    {{-- ── Enrollment lifecycle ───────────────────────────────────────────
         Acts on the student's one LIVE enrollment (pending/active/suspended -
         4.2's "no second live enrollment in one year"). Withdraw needs
         `active` or `suspended`; suspend needs `active`; reinstate needs
         `suspended`; transfer needs any live status. Buttons are shown only
         when the underlying Action's own transition guard would accept them,
         so a click can fail only on a race, never on an always-wrong state. --}}
    @php
        $enrollmentIsActive = $currentEnrollment?->status === \App\Modules\Students\Domain\EnrollmentStatus::Active;
        $enrollmentIsSuspended = $currentEnrollment?->status === \App\Modules\Students\Domain\EnrollmentStatus::Suspended;
        $enrollmentIsLive = $currentEnrollment !== null && $currentEnrollment->status->isLive();
    @endphp
    @if ($currentEnrollment !== null && ($canEditStudent || $canManageEnrollmentLifecycle))
        <section class="rounded border border-border-primary bg-white p-4">
            <div class="flex flex-wrap items-center justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Enrollment</h2>
                <div class="flex flex-wrap items-center gap-2">
                    @if ($canEditStudent && $enrollmentIsLive)
                        <button type="button" wire:click="toggleTransferForm"
                                class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ $showTransferForm ? 'Cancel transfer' : 'Transfer class' }}
                        </button>
                    @endif
                    @if ($canManageEnrollmentLifecycle && $enrollmentIsActive)
                        <button type="button" wire:click="toggleSuspendForm"
                                class="rounded border border-warning/50 px-3 py-1.5 text-sm font-medium text-warning-text hover:bg-warning-bg">
                            {{ $showSuspendForm ? 'Cancel suspend' : 'Suspend' }}
                        </button>
                    @endif
                    @if ($canManageEnrollmentLifecycle && $enrollmentIsSuspended)
                        <button type="button" wire:click="toggleReinstateForm"
                                class="rounded border border-success/50 px-3 py-1.5 text-sm font-medium text-success-text hover:bg-success-bg">
                            {{ $showReinstateForm ? 'Cancel reinstate' : 'Reinstate' }}
                        </button>
                    @endif
                    @if ($canEditStudent && $enrollmentIsLive)
                        <button type="button" wire:click="toggleWithdrawForm"
                                class="rounded border border-danger/50 px-3 py-1.5 text-sm font-medium text-danger-text hover:bg-danger-bg">
                            {{ $showWithdrawForm ? 'Cancel withdraw' : 'Withdraw' }}
                        </button>
                    @endif
                </div>
            </div>
        </section>

        {{-- The four lifecycle forms are POPUPS, not inline panels. They used
             to be stacked inside the section above, each toggled by its own
             boolean, so opening one squeezed a row of inputs into the page
             flow and pushed the profile down. x-opes-modal-form is the
             platform's one dialog shell (focus trap, ESC, backdrop, scroll
             lock); the boolean properties and the wire:submit handlers below
             are untouched - only where they render changed. --}}

        {{-- Suspend --}}
        <x-opes-modal-form wire-model="showSuspendForm" :open="$showSuspendForm" title="Suspend enrollment" max-width="md">
            <form wire:submit.prevent="saveSuspend" class="space-y-3">
                <div>
                    <label class="text-xs text-charcoal/55" for="suspend_reason">Reason for suspension</label>
                    <input id="suspend_reason" type="text" wire:model="suspend_reason"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('suspend_reason') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                    @error('showSuspendForm') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded bg-warning px-3 py-1.5 text-sm font-medium text-white hover:bg-warning-text">
                        Confirm suspend
                    </button>
                    <button type="button" wire:click="$set('showSuspendForm', false)"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </x-opes-modal-form>

        {{-- Reinstate --}}
        <x-opes-modal-form wire-model="showReinstateForm" :open="$showReinstateForm" title="Reinstate enrollment" max-width="md">
            <form wire:submit.prevent="saveReinstate" class="space-y-3">
                <div>
                    <label class="text-xs text-charcoal/55" for="reinstate_reason">Reason for reinstatement</label>
                    <input id="reinstate_reason" type="text" wire:model="reinstate_reason"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('reinstate_reason') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                    @error('showReinstateForm') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div class="flex flex-wrap items-center gap-2">
                    <button type="submit" class="rounded bg-success px-3 py-1.5 text-sm font-medium text-white hover:bg-success-text">
                        Confirm reinstate
                    </button>
                    <button type="button" wire:click="$set('showReinstateForm', false)"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </x-opes-modal-form>

        {{-- Withdraw --}}
        <x-opes-modal-form wire-model="showWithdrawForm" :open="$showWithdrawForm" title="Withdraw student" max-width="xl">
            <form wire:submit.prevent="saveWithdraw" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="text-xs text-charcoal/55" for="withdraw_on">Effective date</label>
                    <input id="withdraw_on" type="date" wire:model="withdraw_on"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('withdraw_on') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="withdraw_to">Outcome</label>
                    <select id="withdraw_to" wire:model="withdraw_to"
                            class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                        <option value="withdrawn">Withdrawn</option>
                        <option value="transferred_out">Transferred out (to another school)</option>
                    </select>
                    @error('withdraw_to') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div class="sm:col-span-2">
                    <label class="text-xs text-charcoal/55" for="withdraw_reason">Reason</label>
                    <input id="withdraw_reason" type="text" wire:model="withdraw_reason"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('withdraw_reason') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                @error('showWithdrawForm') <p class="text-xs text-heritage-red sm:col-span-2">{{ $message }}</p> @enderror
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                    <button type="submit" class="rounded bg-danger px-3 py-1.5 text-sm font-medium text-white hover:bg-danger-text">
                        Confirm withdraw
                    </button>
                    <button type="button" wire:click="$set('showWithdrawForm', false)"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </x-opes-modal-form>

        {{-- Transfer class (mid-year, same academic year and class level) --}}
        <x-opes-modal-form wire-model="showTransferForm" :open="$showTransferForm" title="Transfer class" max-width="xl">
            <form wire:submit.prevent="saveTransfer" class="grid grid-cols-1 gap-3 sm:grid-cols-2">
                <div>
                    <label class="text-xs text-charcoal/55" for="transfer_class_group_id">Target class group</label>
                    <select id="transfer_class_group_id" wire:model="transfer_class_group_id"
                            class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                        <option value="">Select...</option>
                        @foreach ($transferClassGroupOptions as $groupId => $groupName)
                            <option value="{{ $groupId }}">{{ $groupName }}</option>
                        @endforeach
                    </select>
                    @error('transfer_class_group_id') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="transfer_effective_on">Effective date</label>
                    <input id="transfer_effective_on" type="date" wire:model="transfer_effective_on"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('transfer_effective_on') <p class="mt-1 text-xs text-heritage-red">{{ $message }}</p> @enderror
                </div>
                @error('showTransferForm') <p class="text-xs text-heritage-red sm:col-span-2">{{ $message }}</p> @enderror
                <div class="flex flex-wrap items-center gap-2 sm:col-span-2">
                    <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Confirm transfer
                    </button>
                    <button type="button" wire:click="$set('showTransferForm', false)"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </x-opes-modal-form>
    @endif

    {{-- ── Identity header ────────────────────────────────────────────────
         Initials, not the mockup's photograph: photo_path is a PRIVATE-disk
         path served through a policy-checked controller (8.1 / acceptance
         criterion 14) and that controller does not exist yet, so there is
         nothing an <img> could safely point at.

         Deliberately ABSENT from this header, each for a stated reason:
           * Blood group - 3.1 encrypts it and 11.2 marks it permissioned
             (Nurse/Administrator only). No such staff-side permission exists
             in Identity\Domain\Permission, so rendering it here would put a
             child's sickle-cell status in front of every holder of
             students.view.
           * Primary guardian and emergency contact - they live on
             student_guardians, and resolving "primary" needs the 7.3 validity
             predicate, which this module may not evaluate. They are on the
             Guardians tab, resolved by the module that owns the rule.
           * The QR / ID-card block - 10-documents owns the ID card and
             nothing defines what the code would encode. An invented payload
             printed on a child's card is not recoverable.
    --}}
    <section class="rounded border border-border-primary bg-white p-4">
        <div class="flex flex-wrap items-start gap-4">
            <span class="flex h-20 w-20 shrink-0 items-center justify-center rounded-full bg-chrome text-2xl font-semibold uppercase text-white">
                {{ $initials }}
            </span>

            <div class="min-w-0 flex-1 space-y-2">
                <div class="flex flex-wrap items-center gap-2">
                    <h2 class="text-lg font-semibold text-charcoal">{{ $student->fullName() }}</h2>
                    <x-status-pill :status="$statusTone[$student->status->value] ?? 'amber'"
                                    :label="__('opes.students_screen.status_'.$student->status->value)"/>
                </div>

                <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm sm:grid-cols-3 lg:grid-cols-4">
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.column_matricule') }}</dt>
                        <dd class="font-mono text-charcoal">{{ $student->matricule }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.admission_no') }}</dt>
                        <dd class="font-mono text-charcoal">{{ $student->admission_no }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.date_of_birth') }}</dt>
                        <dd class="text-charcoal">
                            {{ $student->date_of_birth->translatedFormat('d M Y') }}
                            {{-- 3.5: age is DERIVED against the business date,
                                 never stored. --}}
                            <span class="text-xs text-charcoal/55">({{ __('opes.students_screen.age', ['years' => $student->ageInYears()]) }})</span>
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.column_class') }}</dt>
                        <dd class="text-charcoal">{{ $currentClassName ?? __('opes.students_screen.no_class') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.gender') }}</dt>
                        <dd class="text-charcoal">{{ __('opes.students_screen.gender_'.$student->gender->value) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.house') }}</dt>
                        <dd class="text-charcoal">{{ $houseName ?? __('opes.students_screen.not_recorded') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.phone') }}</dt>
                        <dd class="text-charcoal">{{ $student->phone ?? __('opes.students_screen.not_recorded') }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.admission_date') }}</dt>
                        <dd class="text-charcoal">{{ $student->first_admission_date?->translatedFormat('d M Y') ?? __('opes.students_screen.not_recorded') }}</dd>
                    </div>
                </dl>
            </div>
        </div>
    </section>

    {{-- ── Tabs ────────────────────────────────────────────────────────────
         All ten are live. `examinations` was removed rather than implemented -
         no examination-result table exists, and a tab promising results while
         showing a seat number is worse than no tab (see the audit at
         docs/superpowers/audits/2026-08-15-inert-controls.md and the
         component's own header).

         The DISABLED_TABS loop is gone with the constant now empty: leaving
         dead markup here is how the next reader concludes the tabs are still
         inert. --}}
    <div class="-mx-4 overflow-x-auto border-b border-border-primary px-4 sm:mx-0 sm:px-0">
        <div role="tablist" aria-label="{{ __('opes.students_screen.breadcrumb_profile') }}" class="flex min-w-max items-center gap-1">
            @foreach (StudentShow::LIVE_TABS as $liveTab)
                <button type="button" role="tab" wire:click="selectTab('{{ $liveTab }}')"
                        aria-selected="{{ $tab === $liveTab ? 'true' : 'false' }}"
                        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $liveTab
                            ? 'border-primary font-semibold text-primary'
                            : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ __('opes.students_screen.tab_'.$liveTab) }}
                </button>
            @endforeach

        </div>
    </div>

    {{-- ── Overview ────────────────────────────────────────────────────────
         Every card's value is NULL where the figure has not been recorded, and
         x-kpi-card draws an em dash for null rather than a 0 (09-ui 3.3). A
         child with no register taken has not been absent. --}}
    @if ($tab === 'overview')
        {{-- grid-cols-1 is spelled out, not left implicit: an auto track is
             what overflowed a real phone in 4e77f64. --}}
        <div class="grid grid-cols-1 gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <x-kpi-card :label="__('opes.students_screen.overview_attendance')"
                        :value="$overviewSummary['attendance_rate']"
                        :sub="$overviewSummary['attendance_rate'] === null ? __('opes.students_screen.overview_no_attendance') : null"
                        tone="green"/>

            <x-kpi-card :label="__('opes.students_screen.overview_marks')"
                        :value="$overviewSummary['marks_count']"
                        :sub="$overviewSummary['marks_count'] === null ? __('opes.students_screen.overview_no_marks') : null"
                        tone="blue"/>

            <x-kpi-card :label="__('opes.students_screen.overview_balance')"
                        :value="$overviewSummary['outstanding_balance'] === null ? null : number_format($overviewSummary['outstanding_balance'], 0, '.', ' ').' FCFA'"
                        :sub="$overviewSummary['outstanding_balance'] === null ? __('opes.students_screen.overview_no_fees') : null"
                        tone="amber"/>

            @if ($canViewDiscipline)
                <x-kpi-card :label="__('opes.students_screen.overview_discipline')"
                            :value="$overviewSummary['discipline_cases']"
                            :sub="$overviewSummary['discipline_cases'] === null ? __('opes.students_screen.overview_no_discipline') : null"
                            tone="pink"/>
            @endif

            <x-kpi-card :label="__('opes.students_screen.overview_documents')"
                        :value="$overviewSummary['documents']"
                        :sub="$overviewSummary['documents'] === null ? __('opes.students_screen.overview_no_documents') : null"
                        tone="purple"/>
        </div>
    @endif

    {{-- ── Academic records ────────────────────────────────────────────── --}}
    @if ($tab === 'academic_records')
        @if ($academicRows->isEmpty())
            <x-empty-state :message="__('opes.students_screen.academic_empty')"/>
        @else
            <div class="space-y-2">
                @foreach ($academicRows as $row)
                    <div class="flex flex-wrap items-center justify-between gap-3 rounded border border-border-primary bg-white px-4 py-3">
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-charcoal">{{ $row->period_name ?? __('opes.students_screen.not_recorded') }}</p>
                            <p class="text-xs text-charcoal/55">
                                {{ __('opes.students_screen.academic_published', ['date' => $row->issued_at]) }}
                            </p>
                        </div>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ── Attendance ──────────────────────────────────────────────────── --}}
    @if ($tab === 'attendance')
        @if ($attendanceRows->isEmpty())
            <x-empty-state :message="__('opes.students_screen.attendance_empty')"/>
        @else
            <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
                <table class="w-full min-w-[40rem] border-collapse text-sm">
                    <thead class="border-b border-border-primary text-left">
                        <tr class="bg-chrome text-white">
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.attendance_date') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.attendance_class') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.attendance_status') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.attendance_remark') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($attendanceRows as $row)
                            <tr wire:key="attendance-{{ $row->id }}">
                                <td class="px-4 py-2.5">{{ $row->date }}</td>
                                <td class="px-4 py-2.5 text-charcoal/70">{{ $row->class_name ?? '—' }}</td>
                                <td class="px-4 py-2.5">
                                    {{-- x-status-pill takes ok|amber|red ONLY; the domain
                                         status goes through as the LABEL, or every row
                                         renders a green "OK". --}}
                                    <x-status-pill :status="$attendanceTone[$row->status] ?? 'amber'"
                                                   :label="__('opes.students_screen.attendance_state_'.$row->status)"/>
                                </td>
                                <td class="px-4 py-2.5 text-charcoal/70">{{ $row->remark ?: '—' }}</td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($attendanceTotal > $listLimit)
                <p class="text-xs text-charcoal/55">{{ __('opes.ui.showing', ['first' => 1, 'last' => $listLimit, 'total' => $attendanceTotal]) }}</p>
            @endif
        @endif
    @endif

    {{-- ── Fees ────────────────────────────────────────────────────────── --}}
    @if ($tab === 'fees')
        @if ($feeRows->isEmpty())
            <x-empty-state :message="__('opes.students_screen.fees_empty')"/>
        @else
            <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
                <table class="w-full min-w-[44rem] border-collapse text-sm">
                    <thead class="border-b border-border-primary text-left">
                        <tr class="bg-chrome text-white">
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.fees_invoice') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.fees_issued') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.fees_status') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.fees_total') }}</th>
                            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.fees_balance') }}</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border-primary">
                        @foreach ($feeRows as $row)
                            <tr wire:key="invoice-{{ $row->id }}">
                                <td class="px-4 py-2.5 font-mono text-xs">{{ $row->invoice_no }}</td>
                                <td class="px-4 py-2.5 text-charcoal/70">{{ $row->issue_date }}</td>
                                <td class="px-4 py-2.5">
                                    <x-status-pill :status="$invoiceTone[$row->status] ?? 'amber'"
                                                   :label="__('opes.students_screen.fees_state_'.$row->status)"/>
                                </td>
                                <td class="px-4 py-2.5 text-right tabular-nums">{{ number_format((int) $row->total_amount, 0, '.', ' ') }}</td>
                                <td class="px-4 py-2.5 text-right font-medium tabular-nums">
                                    {{ number_format(max(0, (int) $row->total_amount - (int) $row->paid_amount), 0, '.', ' ') }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($feeTotal > $listLimit)
                <p class="text-xs text-charcoal/55">{{ __('opes.ui.showing', ['first' => 1, 'last' => $listLimit, 'total' => $feeTotal]) }}</p>
            @endif
        @endif
    @endif

    {{-- ── Discipline ──────────────────────────────────────────────────── --}}
    @if ($tab === 'discipline')
        @if (! $canViewDiscipline)
            {{-- Said, not hidden: an operator who cannot see conduct records
                 should know they exist rather than conclude this child has a
                 clean record. --}}
            <x-empty-state :message="__('opes.students_screen.discipline_forbidden')"/>
        @elseif ($disciplineRows->isEmpty())
            <x-empty-state :message="__('opes.students_screen.discipline_empty')"/>
        @else
            <div class="space-y-3">
                @foreach ($disciplineRows as $row)
                    <div wire:key="discipline-{{ $row->id }}" class="rounded border border-border-primary bg-white p-4">
                        <div class="flex flex-wrap items-center gap-2">
                            <x-status-pill :status="$disciplineTone[$row->status] ?? 'amber'"
                                           :label="__('opes.students_screen.discipline_state_'.$row->status)"/>
                            <span class="text-xs text-charcoal/55">{{ $row->occurred_on }}</span>
                            @if ((bool) $row->is_positive)
                                <span class="text-xs font-medium text-primary">{{ __('opes.students_screen.discipline_positive') }}</span>
                            @endif
                        </div>
                        <p class="mt-2 text-sm font-medium text-charcoal">
                            {{ $row->category_name ?? __('opes.students_screen.not_recorded') }}
                            @if ($row->category_severity !== null)
                                <span class="text-xs font-normal text-charcoal/55">
                                    · {{ __('opes.students_screen.discipline_severity', ['level' => $row->category_severity]) }}
                                </span>
                            @endif
                        </p>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->description }}</p>
                    </div>
                @endforeach
            </div>
        @endif
    @endif

    {{-- ── General ─────────────────────────────────────────────────────── --}}
    @if ($tab === 'general')
        <section class="rounded border border-border-primary bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.students_screen.general_heading') }}
            </h2>
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                {{-- Plain, non-sensitive biography only. The four ENCRYPTED
                     columns of 3.1 (religion, blood group, genotype, national
                     ID number) are omitted: 00-core 9.5 encrypts them because
                     they are special-category data about a child, and no
                     staff-side permission narrower than students.view exists
                     yet to gate them. --}}
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.column_student') }}</dt>
                    <dd class="text-charcoal">{{ $student->fullName() }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.column_matricule') }}</dt>
                    <dd class="font-mono text-charcoal">{{ $student->matricule }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.admission_no') }}</dt>
                    <dd class="font-mono text-charcoal">{{ $student->admission_no }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.column_status') }}</dt>
                    <dd>
                        <x-status-pill :status="$statusTone[$student->status->value] ?? 'amber'"
                                        :label="__('opes.students_screen.status_'.$student->status->value)"/>
                    </dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.gender') }}</dt>
                    <dd class="text-charcoal">{{ __('opes.students_screen.gender_'.$student->gender->value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.date_of_birth') }}</dt>
                    <dd class="text-charcoal">{{ $student->date_of_birth->translatedFormat('d F Y') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.place_of_birth') }}</dt>
                    <dd class="text-charcoal">{{ $student->place_of_birth ?? __('opes.students_screen.not_recorded') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.nationality') }}</dt>
                    <dd class="text-charcoal">{{ $student->nationality }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.email') }}</dt>
                    <dd class="truncate text-charcoal">{{ $student->email ?? __('opes.students_screen.not_recorded') }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.phone') }}</dt>
                    <dd class="text-charcoal">{{ $student->phone ?? __('opes.students_screen.not_recorded') }}</dd>
                </div>
                <div class="sm:col-span-2">
                    <dt class="text-xs text-charcoal/55">{{ __('opes.students_screen.address') }}</dt>
                    <dd class="text-charcoal">
                        {{ trim(implode(', ', array_filter([$student->address_line, $student->city, $student->region]))) ?: __('opes.students_screen.not_recorded') }}
                    </dd>
                </div>
            </dl>
        </section>
    @endif

    {{-- ── Guardians. Rendered by the Guardians module: see the component's
         class header for why this is a nested component and not a partial. --}}
    @if ($tab === 'guardians')
        <livewire:students.guardians-panel :student-id="$student->id" :key="'guardians-panel-'.$student->id"/>
    @endif

    {{-- ── Documents ───────────────────────────────────────────────────── --}}
    @if ($tab === 'documents')
        {{-- Printable documents (docs/specs/10-documents.md §7): the eight
             front-desk documents, rendered inline as PDF by the
             students.documents.* routes so the operator previews before
             printing. Visible only to documents.print holders - the same
             gate the routes carry. Refusals (clearance, discipline, empty
             attendance denominator) come back as a plain-text 422 message
             in the opened tab; the override inputs feed the
             documents.override_gate path (§19). --}}
        @can('documents.print')
            <section class="mb-4 space-y-3 rounded border border-border-primary bg-white p-4">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.students_screen.print_documents_heading') }}
                </h2>

                {{-- Said out loud, not implied by a watermark the operator may
                     not scroll to: a preview is not a certificate. Every
                     Preview control below hits the SAME route with ?preview=1,
                     so it renders the same payload the Print button would
                     issue - it just never allocates a number or records
                     anything. --}}
                <p class="text-xs text-charcoal/55">{{ __('opes.students_screen.preview_not_issued') }}</p>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('students.documents.print', [$student->id, 'info-sheet', 'preview' => 1]) }}" target="_blank"
                       class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_info_sheet') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </a>
                    <a href="{{ route('students.documents.print', [$student->id, 'bonafide', 'preview' => 1]) }}" target="_blank"
                       class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_bonafide') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </a>
                    <a href="{{ route('students.documents.print', [$student->id, 'admission-form', 'preview' => 1]) }}" target="_blank"
                       class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_admission_form') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </a>
                </div>

                <div class="flex flex-wrap items-center gap-2">
                    <a href="{{ route('students.documents.print', [$student->id, 'info-sheet']) }}" target="_blank"
                       class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_info_sheet') }}
                    </a>
                    <a href="{{ route('students.documents.print', [$student->id, 'bonafide']) }}" target="_blank"
                       class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_bonafide') }}
                    </a>
                    <a href="{{ route('students.documents.print', [$student->id, 'admission-form']) }}" target="_blank"
                       class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_admission_form') }}
                    </a>
                    <a href="{{ route('students.documents.admission-form') }}" target="_blank"
                       class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_admission_form_blank') }}
                    </a>
                </div>

                {{-- Attendance attestation: §7.11 needs the stated range. --}}
                <form method="GET" target="_blank"
                      action="{{ route('students.documents.print', [$student->id, 'attendance-certificate']) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div>
                        <label class="text-xs text-charcoal/55" for="doc_att_from">{{ __('opes.students_screen.print_attendance_from') }}</label>
                        <input id="doc_att_from" name="from" type="date" required
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <div>
                        <label class="text-xs text-charcoal/55" for="doc_att_to">{{ __('opes.students_screen.print_attendance_to') }}</label>
                        <input id="doc_att_to" name="to" type="date" required
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <button type="submit" name="preview" value="1"
                            class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_attendance_cert') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </button>
                    <button type="submit"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_attendance_cert') }}
                    </button>
                </form>

                {{-- Testimonial: §7.9's authored narrative. --}}
                <form method="GET" target="_blank"
                      action="{{ route('students.documents.print', [$student->id, 'testimonial']) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[20rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="doc_testimonial_body">{{ __('opes.students_screen.print_testimonial_body') }}</label>
                        <textarea id="doc_testimonial_body" name="body" rows="2" required
                                  class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"></textarea>
                    </div>
                    <button type="submit" name="preview" value="1"
                            class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_testimonial') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </button>
                    <button type="submit"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_testimonial') }}
                    </button>
                </form>

                {{-- Departure and conduct certificates: gated documents whose
                     refusal message names the block; the override reason is
                     honoured only for documents.override_gate holders. --}}
                <form method="GET" target="_blank"
                      action="{{ route('students.documents.print', [$student->id, 'transfer-certificate']) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[14rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="doc_transfer_reason">{{ __('opes.students_screen.print_transfer_reason') }}</label>
                        <input id="doc_transfer_reason" name="reason" type="text"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <div class="min-w-[14rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="doc_transfer_override">{{ __('opes.students_screen.print_override_reason') }}</label>
                        <input id="doc_transfer_override" name="override_reason" type="text"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <button type="submit" name="preview" value="1"
                            class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_transfer_cert') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </button>
                    <button type="submit"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_transfer_cert') }}
                    </button>
                </form>

                <form method="GET" target="_blank"
                      action="{{ route('students.documents.print', [$student->id, 'leaving-certificate']) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[14rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="doc_leaving_override">{{ __('opes.students_screen.print_override_reason') }}</label>
                        <input id="doc_leaving_override" name="override_reason" type="text"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <button type="submit" name="preview" value="1"
                            class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_leaving_cert') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </button>
                    <button type="submit"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_leaving_cert') }}
                    </button>
                </form>

                <form method="GET" target="_blank"
                      action="{{ route('students.documents.print', [$student->id, 'character-certificate']) }}"
                      class="flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[14rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="doc_char_override">{{ __('opes.students_screen.print_override_reason') }}</label>
                        <input id="doc_char_override" name="override_reason" type="text"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </div>
                    <button type="submit" name="preview" value="1"
                            class="rounded border border-dashed border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/70 hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_char_cert') }} <span class="text-xs">{{ __('opes.students_screen.preview_suffix') }}</span>
                    </button>
                    <button type="submit"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ __('opes.students_screen.print_char_cert') }}
                    </button>
                </form>
            </section>
        @endcan

        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.students_screen.documents_heading') }}
                </h2>
            </div>

            {{-- The control was inert with a comment saying a file input
                 "would write somewhere unspecified". Phase 1's upload work
                 specified it: PDFs and images only, 5 MB, content hashed on
                 the way in. The file name is still not a download link - the
                 policy-checked serving controller of 8.1 is a separate
                 piece. --}}
            @can('students.manage')
                <div class="flex flex-wrap items-end gap-2 rounded border border-border-primary bg-white p-3">
                    <label class="block text-sm">
                        <span class="mb-1 block text-xs text-charcoal/55">{{ __('opes.students_screen.document_title') }}</span>
                        <input type="text" wire:model="documentTitle"
                               class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    </label>
                    <input type="file" wire:model="documentUpload" accept=".pdf,image/png,image/jpeg,image/webp"
                           class="block text-sm text-charcoal file:mr-3 file:rounded file:border-0 file:bg-primary file:px-3 file:py-2 file:text-sm file:font-medium file:text-white hover:file:bg-primary/90"/>
                    <button type="button" wire:click="saveDocument"
                            class="rounded border border-primary bg-primary px-3 py-2 text-sm font-medium text-white hover:bg-primary/90">
                        {{ __('opes.students_screen.upload_document') }}
                    </button>
                </div>
                @error('documentUpload') <p class="text-xs font-medium text-heritage-red">{{ $message }}</p> @enderror
                @error('documentTitle') <p class="text-xs font-medium text-heritage-red">{{ $message }}</p> @enderror
            @endcan

            @if ($documents->isEmpty())
                <x-empty-state :message="__('opes.students_screen.documents_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-border-primary text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_document') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_issued_on') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_verification') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            @foreach ($documents as $document)
                                <tr wire:key="student-document-{{ $document->id }}">
                                    <td class="px-4 py-2.5">
                                        <div class="font-medium text-charcoal">{{ $document->title }}</div>
                                        <div class="text-xs text-charcoal/55">{{ $document->mime }}</div>
                                    </td>
                                    <td class="px-4 py-2.5 text-charcoal/70">
                                        {{ $document->issued_on?->translatedFormat('d M Y') ?? __('opes.students_screen.not_recorded') }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <x-status-pill :status="$verificationTone[$document->verification_status->value] ?? 'amber'"
                                                        :label="__('opes.students_screen.verification_'.$document->verification_status->value)"/>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($documentsTotal > $listLimit)
                    <p class="text-xs text-charcoal/55">{{ __('opes.ui.showing', ['first' => 1, 'last' => $listLimit, 'total' => $documentsTotal]) }}</p>
                @endif
            @endif
        </section>
    @endif

    {{-- ── Medical ─────────────────────────────────────────────────────── --}}
    @if ($tab === 'medical')
        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.students_screen.medical_heading') }}
                </h2>
                <p class="text-xs text-charcoal/50">{{ __('opes.students_screen.read_only_notice') }}</p>
            </div>

            {{-- `summary` and the emergency flag only. 8.2 encrypts `detail`
                 and restricts the full record to Nurse + Administrator; no such
                 staff-side permission exists in Identity\Domain\Permission yet,
                 so the narrow view is the one that ships. Widening it later is
                 a permission change; narrowing it after a term of exposure is
                 not a change anyone can make. --}}
            @if ($medicalRecords->isEmpty())
                <x-empty-state :message="__('opes.students_screen.medical_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-border-primary bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-border-primary text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_condition') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_summary') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_severity') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_emergency_relevant') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-border-primary">
                            @foreach ($medicalRecords as $record)
                                <tr wire:key="student-medical-{{ $record->id }}">
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.students_screen.condition_'.$record->condition_type->value) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal">{{ $record->summary }}</td>
                                    <td class="px-4 py-2.5">
                                        <x-status-pill :status="$severityTone[$record->severity->value] ?? 'amber'"
                                                        :label="__('opes.students_screen.severity_'.$record->severity->value)"/>
                                    </td>
                                    <td class="px-4 py-2.5 text-charcoal/70">
                                        {{ $record->is_emergency_relevant ? __('opes.students_screen.yes') : __('opes.students_screen.no') }}
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($medicalTotal > $listLimit)
                    <p class="text-xs text-charcoal/55">{{ __('opes.ui.showing', ['first' => 1, 'last' => $listLimit, 'total' => $medicalTotal]) }}</p>
                @endif
            @endif
        </section>
    @endif

    {{-- ── Activity log ────────────────────────────────────────────────────
         A closed event taxonomy (Students\Domain\StudentActivityEvent), one
         label per case. An unknown event would otherwise print its raw enum
         value at a guardian, so the label falls back to the summary line the
         writing Action supplied. --}}
    @if ($tab === 'activity_log')
        @if ($activityRows->isEmpty())
            <x-empty-state :message="__('opes.students_screen.activity_empty')"/>
        @else
            <ol class="space-y-2">
                @foreach ($activityRows as $entry)
                    <li wire:key="activity-{{ $entry->id }}" class="flex gap-3 rounded border border-border-primary bg-white px-4 py-3">
                        <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-primary" aria-hidden="true"></span>
                        <div class="min-w-0">
                            <p class="text-sm font-medium text-charcoal">
                                {{ __('opes.students_screen.activity_'.$entry->event->value) }}
                            </p>
                            <p class="text-sm text-charcoal/70">{{ $entry->summary }}</p>
                            <p class="mt-0.5 text-xs text-charcoal/55">
                                {{ $entry->occurred_at?->translatedFormat('d M Y H:i') }}
                                @if ($entry->actor_name_at_time !== null)
                                    · {{ $entry->actor_name_at_time }}
                                @endif
                            </p>
                        </div>
                    </li>
                @endforeach
            </ol>
        @endif
    @endif
</div>
