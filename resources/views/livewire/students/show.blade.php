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

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ __('opes.students_screen.breadcrumb_profile') }}</h1>
        <a href="{{ route('students.index') }}"
           class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.students_screen.back_to_list') }}
        </a>
    </div>

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
    <section class="rounded border border-sand bg-white p-4">
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
    <div class="-mx-4 overflow-x-auto border-b border-sand px-4 sm:mx-0 sm:px-0">
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
        <section class="rounded border border-sand bg-white p-4">
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
                      class="cursor-not-allowed rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal/40">
                    {{ __('opes.students_screen.upload_document') }}
                </span>
            </div>

            @if ($documents->isEmpty())
                <x-empty-state :message="__('opes.students_screen.documents_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-sand text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_document') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_issued_on') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_verification') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
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
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-sand text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_condition') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_summary') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_severity') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.students_screen.column_emergency_relevant') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
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
