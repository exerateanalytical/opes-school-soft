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
        <div class="rounded border border-emerald-300 bg-emerald-50 px-3 py-2 text-sm text-emerald-800">
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
    @if ($showEditForm)
        <section class="rounded border border-border-primary bg-white p-4">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">Edit profile</h2>
            <form wire:submit.prevent="saveEdit" class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_first_name">First name</label>
                    <input id="edit_first_name" type="text" wire:model="edit_first_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_first_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_middle_name">Middle name</label>
                    <input id="edit_middle_name" type="text" wire:model="edit_middle_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_middle_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_last_name">Last name</label>
                    <input id="edit_last_name" type="text" wire:model="edit_last_name"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_last_name') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_date_of_birth">Date of birth</label>
                    <input id="edit_date_of_birth" type="date" wire:model="edit_date_of_birth"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_date_of_birth') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_gender">Gender</label>
                    <select id="edit_gender" wire:model="edit_gender"
                            class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                        <option value="male">{{ __('opes.students_screen.gender_male') }}</option>
                        <option value="female">{{ __('opes.students_screen.gender_female') }}</option>
                    </select>
                    @error('edit_gender') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_phone">Phone</label>
                    <input id="edit_phone" type="text" wire:model="edit_phone"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_phone') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                <div>
                    <label class="text-xs text-charcoal/55" for="edit_email">Email</label>
                    <input id="edit_email" type="email" wire:model="edit_email"
                           class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                    @error('edit_email') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                </div>
                @error('showEditForm') <p class="text-xs text-red-600 sm:col-span-2 lg:col-span-3">{{ $message }}</p> @enderror
                <div class="flex items-center gap-2 sm:col-span-2 lg:col-span-3">
                    <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                        Save changes
                    </button>
                    <button type="button" wire:click="toggleEditForm" class="rounded border border-border-primary px-3 py-1.5 text-sm text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

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
                                class="rounded border border-amber-300 px-3 py-1.5 text-sm font-medium text-amber-700 hover:bg-amber-50">
                            {{ $showSuspendForm ? 'Cancel suspend' : 'Suspend' }}
                        </button>
                    @endif
                    @if ($canManageEnrollmentLifecycle && $enrollmentIsSuspended)
                        <button type="button" wire:click="toggleReinstateForm"
                                class="rounded border border-emerald-300 px-3 py-1.5 text-sm font-medium text-emerald-700 hover:bg-emerald-50">
                            {{ $showReinstateForm ? 'Cancel reinstate' : 'Reinstate' }}
                        </button>
                    @endif
                    @if ($canEditStudent && $enrollmentIsLive)
                        <button type="button" wire:click="toggleWithdrawForm"
                                class="rounded border border-red-300 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                            {{ $showWithdrawForm ? 'Cancel withdraw' : 'Withdraw' }}
                        </button>
                    @endif
                </div>
            </div>

            {{-- Suspend --}}
            @if ($showSuspendForm)
                <form wire:submit.prevent="saveSuspend" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[16rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="suspend_reason">Reason for suspension</label>
                        <input id="suspend_reason" type="text" wire:model="suspend_reason"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        @error('suspend_reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('showSuspendForm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded bg-amber-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-amber-700">
                        Confirm suspend
                    </button>
                </form>
            @endif

            {{-- Reinstate --}}
            @if ($showReinstateForm)
                <form wire:submit.prevent="saveReinstate" class="mt-3 flex flex-wrap items-end gap-2 border-t border-border-primary pt-3">
                    <div class="min-w-[16rem] flex-1">
                        <label class="text-xs text-charcoal/55" for="reinstate_reason">Reason for reinstatement</label>
                        <input id="reinstate_reason" type="text" wire:model="reinstate_reason"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        @error('reinstate_reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                        @error('showReinstateForm') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <button type="submit" class="rounded bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">
                        Confirm reinstate
                    </button>
                </form>
            @endif

            {{-- Withdraw --}}
            @if ($showWithdrawForm)
                <form wire:submit.prevent="saveWithdraw" class="mt-3 grid grid-cols-1 gap-3 border-t border-border-primary pt-3 sm:grid-cols-3">
                    <div>
                        <label class="text-xs text-charcoal/55" for="withdraw_on">Effective date</label>
                        <input id="withdraw_on" type="date" wire:model="withdraw_on"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        @error('withdraw_on') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs text-charcoal/55" for="withdraw_to">Outcome</label>
                        <select id="withdraw_to" wire:model="withdraw_to"
                                class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                            <option value="withdrawn">Withdrawn</option>
                            <option value="transferred_out">Transferred out (to another school)</option>
                        </select>
                        @error('withdraw_to') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div class="sm:col-span-1">
                        <label class="text-xs text-charcoal/55" for="withdraw_reason">Reason</label>
                        <input id="withdraw_reason" type="text" wire:model="withdraw_reason"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        @error('withdraw_reason') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    @error('showWithdrawForm') <p class="text-xs text-red-600 sm:col-span-3">{{ $message }}</p> @enderror
                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded bg-red-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-red-700">
                            Confirm withdraw
                        </button>
                    </div>
                </form>
            @endif

            {{-- Transfer class (mid-year, same academic year and class level) --}}
            @if ($showTransferForm)
                <form wire:submit.prevent="saveTransfer" class="mt-3 grid grid-cols-1 gap-3 border-t border-border-primary pt-3 sm:grid-cols-3">
                    <div>
                        <label class="text-xs text-charcoal/55" for="transfer_class_group_id">Target class group</label>
                        <select id="transfer_class_group_id" wire:model="transfer_class_group_id"
                                class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm">
                            <option value="">Select...</option>
                            @foreach ($transferClassGroupOptions as $groupId => $groupName)
                                <option value="{{ $groupId }}">{{ $groupName }}</option>
                            @endforeach
                        </select>
                        @error('transfer_class_group_id') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    <div>
                        <label class="text-xs text-charcoal/55" for="transfer_effective_on">Effective date</label>
                        <input id="transfer_effective_on" type="date" wire:model="transfer_effective_on"
                               class="mt-1 w-full rounded border border-border-primary px-2 py-1.5 text-sm"/>
                        @error('transfer_effective_on') <p class="mt-1 text-xs text-red-600">{{ $message }}</p> @enderror
                    </div>
                    @error('showTransferForm') <p class="text-xs text-red-600 sm:col-span-3">{{ $message }}</p> @enderror
                    <div class="sm:col-span-3">
                        <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            Confirm transfer
                        </button>
                    </div>
                </form>
            @endif
        </section>
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
         Four live, seven inert. A disabled tab is PRESENT (so the operator can
         see the shape of the finished product and knows the data is not simply
         missing) but carries aria-disabled and the shell-wide "arrives later"
         title, and can never be selected. It is never filled with a plausible
         empty grid, which would read as "this child has no marks". --}}
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

            @foreach (StudentShow::DISABLED_TABS as $disabledTab)
                <span role="tab" aria-disabled="true" aria-selected="false"
                      title="{{ __('opes.nav.nav_disabled_title') }}"
                      class="cursor-not-allowed whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm text-charcoal/30">
                    {{ __('opes.students_screen.tab_'.$disabledTab) }}
                </span>
            @endforeach
        </div>
    </div>

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
        <section class="space-y-3">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.students_screen.documents_heading') }}
                </h2>
                {{-- 8.1 puts every document on a PRIVATE disk, served through a
                     policy-checked controller, with a SHA-256 hash unique per
                     student and a quarantine-then-delete lifecycle. None of
                     that transport exists yet, so the upload control is inert
                     rather than a file input that would write somewhere
                     unspecified - and, for the same reason, the file name is
                     not a download link. --}}
                <span aria-disabled="true" title="{{ __('opes.students_screen.upload_disabled') }}"
                      class="cursor-not-allowed rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal/40">
                    {{ __('opes.students_screen.upload_document') }}
                </span>
            </div>

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
</div>
