@php
    use App\Modules\Guardians\Livewire\Guardians\Show as GuardianShow;
    use App\Modules\Guardians\Livewire\Support\LinkPresentation;

    // Same mapping as the two Students screens - a status must read the same
    // colour everywhere. The VALUES are plain strings here because this view is
    // rendered by the Guardians module, which reads the student's status out of
    // a query-builder row rather than a Students enum.
    $studentStatusTone = [
        'active' => 'ok',
        'graduated' => 'ok',
        'prospective' => 'amber',
        'inactive' => 'amber',
        'transferred_out' => 'red',
        'withdrawn' => 'red',
        'deceased' => 'red',
    ];

    $validityTone = ['current' => 'ok', 'pending' => 'amber', 'expired' => 'red'];

    $initials = mb_strtoupper(mb_substr($guardian->first_name, 0, 1).mb_substr($guardian->last_name, 0, 1));

    $address = trim(implode(', ', array_filter([
        $guardian->address_line, $guardian->city, $guardian->region, $guardian->country,
    ])));

    $notRecorded = __('opes.guardians_screen.not_recorded');
@endphp

@push('sidebar-quick-actions')
    <div class="mx-3 mt-auto rounded-lg border border-heritage-yellow/70 p-3">
        <h2 class="text-xs font-bold uppercase tracking-wide text-heritage-yellow">
            {{ __('opes.dashboard.quick_actions') }}
        </h2>
        <ul class="mt-2 space-y-1">
            {{-- 7.6 routes every authorization change through
                 SetGuardianAuthorization, and 7.7's merge is irreversible and
                 permissioned. Neither has a screen in Phase 2, so both are
                 inert rather than linked. --}}
            @foreach (['Add New Guardian', 'Import Guardians', 'Edit Profile'] as $unbuilt)
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

    <nav aria-label="{{ __('opes.ui.breadcrumb') }}" class="min-w-0">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.guardians_screen.breadcrumb_dashboard') }}</li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span>{{ __('opes.guardians_screen.breadcrumb_guardians') }}</span>
            </li>
            <li class="flex items-center gap-1">
                <span aria-hidden="true" class="text-charcoal/30">/</span>
                <span aria-current="page" class="font-medium text-charcoal/80">{{ $guardian->fullName() }}</span>
            </li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-start justify-between gap-3">
        <h1 class="min-w-0 text-xl font-semibold text-charcoal">{{ __('opes.guardians_screen.title') }}</h1>
        {{-- 7.6: an edit control that did not close-and-succeed the link row,
             audit the before/after flags and revoke the portal session would
             break exactly the trail that section exists to protect. --}}
        <span aria-disabled="true" title="{{ __('opes.nav.nav_disabled_title') }}"
              class="cursor-not-allowed rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal/40">
            {{ __('opes.guardians_screen.read_only_notice') }}
        </span>
    </div>

    <div class="grid grid-cols-1 gap-4 lg:grid-cols-3">

        {{-- ── Left: identity + contact ────────────────────────────────── --}}
        <section class="space-y-4">
            <div class="rounded border border-sand bg-white p-4 text-center">
                {{-- Initials, not the mockup's portrait: photo_path is a
                     private-disk path (7.1) with no policy-checked serving
                     controller in Phase 2. --}}
                <span class="mx-auto flex h-24 w-24 items-center justify-center rounded-full bg-chrome text-2xl font-semibold uppercase text-white">
                    {{ $initials }}
                </span>
                <div class="mt-3 flex justify-center">
                    <x-status-pill :status="$guardian->isActive() ? 'ok' : 'red'"
                                    :label="$guardian->isActive()
                                        ? __('opes.guardians_screen.status_active')
                                        : __('opes.guardians_screen.status_inactive')"/>
                </div>
                <h2 class="mt-2 text-lg font-semibold uppercase text-charcoal">{{ $guardian->fullName() }}</h2>
                <p class="font-mono text-xs text-charcoal/55">{{ $guardian->guardian_no }}</p>

                <div class="mt-4 border-t border-sand pt-3 text-left">
                    <h3 class="text-xs font-semibold uppercase tracking-wide text-charcoal/60">
                        {{ __('opes.guardians_screen.contact_heading') }}
                    </h3>
                    <ul class="mt-2 space-y-1.5 text-sm text-charcoal/80">
                        <li>{{ $guardian->phone }}</li>
                        @if ($guardian->alternative_phone !== null)
                            <li>{{ $guardian->alternative_phone }}</li>
                        @endif
                        <li class="break-all">{{ $guardian->email ?? $notRecorded }}</li>
                        <li>{{ $address !== '' ? $address : $notRecorded }}</li>
                    </ul>
                </div>
            </div>
        </section>

        {{-- ── Centre: guardian details ───────────────────────────────── --}}
        <section class="rounded border border-sand bg-white p-4 lg:col-span-1">
            <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.guardians_screen.details_heading') }}
            </h2>

            {{-- id_number is ENCRYPTED (7.1) and is the 7.7 duplicate-detection
                 key. It is deliberately NOT printed: no staff-side permission
                 narrower than students.view exists yet, and this screen is
                 reachable by everyone who may read a student. The ID TYPE is
                 shown, which is what an operator needs to ask for the card. --}}
            <dl class="grid grid-cols-1 gap-x-6 gap-y-3 text-sm sm:grid-cols-2">
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.full_name') }}</dt>
                    <dd class="text-charcoal">{{ trim(($guardian->title ?? '').' '.$guardian->fullName()) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.marital_status') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->marital_status === null ? $notRecorded : __('opes.guardians_screen.marital_'.$guardian->marital_status->value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.date_of_birth') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->date_of_birth?->translatedFormat('d F Y') ?? $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.phone') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->phone }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.gender') }}</dt>
                    <dd class="text-charcoal">{{ __('opes.guardians_screen.gender_'.$guardian->gender->value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.alternative_phone') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->alternative_phone ?? $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.nationality') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->nationality }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.email') }}</dt>
                    <dd class="truncate text-charcoal">{{ $guardian->email ?? $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.id_type') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->id_type === null ? $notRecorded : __('opes.guardians_screen.id_'.$guardian->id_type->value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.home_address') }}</dt>
                    <dd class="text-charcoal">{{ $address !== '' ? $address : $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.occupation') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->occupation ?? $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.residential_status') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->residential_status === null ? $notRecorded : __('opes.guardians_screen.residential_'.$guardian->residential_status->value) }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.employer') }}</dt>
                    <dd class="text-charcoal">{{ $guardian->employer ?? $notRecorded }}</dd>
                </div>
                <div>
                    <dt class="text-xs text-charcoal/55">{{ __('opes.guardians_screen.preferred_contact') }}</dt>
                    <dd class="text-charcoal">{{ __('opes.guardians_screen.contact_'.$guardian->preferred_contact_method->value) }}</dd>
                </div>
            </dl>
        </section>

        {{-- ── Right: emergency contact + preferences ─────────────────── --}}
        <section class="space-y-4">
            <div class="rounded border border-sand bg-white p-4">
                <h2 class="mb-3 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.guardians_screen.emergency_heading') }}
                </h2>
                <dl class="space-y-2 text-sm">
                    <div class="flex justify-between gap-3">
                        <dt class="text-charcoal/55">{{ __('opes.guardians_screen.emergency_name') }}</dt>
                        <dd class="text-right text-charcoal">{{ $guardian->emergency_contact_name ?? $notRecorded }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-charcoal/55">{{ __('opes.guardians_screen.emergency_relationship') }}</dt>
                        <dd class="text-right text-charcoal">{{ $guardian->emergency_contact_relationship ?? $notRecorded }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-charcoal/55">{{ __('opes.guardians_screen.emergency_phone') }}</dt>
                        <dd class="text-right text-charcoal">{{ $guardian->emergency_contact_phone ?? $notRecorded }}</dd>
                    </div>
                    <div class="flex justify-between gap-3">
                        <dt class="text-charcoal/55">{{ __('opes.guardians_screen.emergency_address') }}</dt>
                        <dd class="text-right text-charcoal">{{ $guardian->emergency_contact_address ?? $notRecorded }}</dd>
                    </div>
                </dl>
            </div>

            {{-- 7.4: these five are DELIVERY preferences - "do we push this to
                 them" - and are NOT authorization. What the guardian may see is
                 decided per child on the link, and is in the Permissions column
                 of the table below. The two are drawn apart deliberately: v1
                 conflated them, which is the defect 7.4 exists to name. --}}
            <div class="rounded border border-sand bg-white p-4">
                <h2 class="mb-1 text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                    {{ __('opes.guardians_screen.preferences_heading') }}
                </h2>
                <dl class="mt-2 space-y-2 text-sm">
                    @foreach ([
                        'notify_sms' => $guardian->notify_sms,
                        'notify_email' => $guardian->notify_email,
                        'notify_push' => $guardian->notify_push,
                        'receives_reports' => $guardian->receives_reports,
                        'receives_invoices' => $guardian->receives_invoices,
                    ] as $prefKey => $prefOn)
                        <div class="flex items-center justify-between gap-3">
                            <dt class="text-charcoal/70">{{ __('opes.guardians_screen.'.$prefKey) }}</dt>
                            <dd>
                                <x-status-pill :status="$prefOn ? 'ok' : 'amber'"
                                                :label="$prefOn ? __('opes.students_screen.yes') : __('opes.students_screen.no')"/>
                            </dd>
                        </div>
                    @endforeach
                    <div class="flex items-center justify-between gap-3 border-t border-sand pt-2">
                        <dt class="text-charcoal/70">{{ __('opes.guardians_screen.language') }}</dt>
                        <dd class="text-charcoal">{{ __('opes.guardians_screen.language_'.$guardian->language->value) }}</dd>
                    </div>
                </dl>
            </div>
        </section>
    </div>

    {{-- ── Tabs ───────────────────────────────────────────────────────── --}}
    <div class="-mx-4 overflow-x-auto border-b border-sand px-4 sm:mx-0 sm:px-0">
        <div role="tablist" aria-label="{{ __('opes.guardians_screen.title') }}" class="flex min-w-max items-center gap-1">
            @foreach (GuardianShow::LIVE_TABS as $liveTab)
                <button type="button" role="tab" wire:click="selectTab('{{ $liveTab }}')"
                        aria-selected="{{ $tab === $liveTab ? 'true' : 'false' }}"
                        class="whitespace-nowrap border-b-2 px-3 py-2 text-sm {{ $tab === $liveTab
                            ? 'border-primary font-semibold text-primary'
                            : 'border-transparent text-charcoal/60 hover:text-charcoal' }}">
                    {{ __('opes.guardians_screen.tab_'.$liveTab) }}
                </button>
            @endforeach

            {{-- Address & Contact duplicates the two cards above; Documents has
                 no GuardianDocument table in Phase 2; Payments is 04-fees. All
                 three inert, none faked. --}}
            @foreach (GuardianShow::DISABLED_TABS as $disabledTab)
                <span role="tab" aria-disabled="true" aria-selected="false"
                      title="{{ __('opes.nav.nav_disabled_title') }}"
                      class="cursor-not-allowed whitespace-nowrap border-b-2 border-transparent px-3 py-2 text-sm text-charcoal/30">
                    {{ __('opes.guardians_screen.tab_'.$disabledTab) }}
                </span>
            @endforeach
        </div>
    </div>

    {{-- ── Linked students ────────────────────────────────────────────── --}}
    @if ($tab === 'linked_students')
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.guardians_screen.linked_heading') }}
            </h2>

            @if ($links->isEmpty())
                <x-empty-state :message="__('opes.guardians_screen.linked_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[52rem] border-collapse text-sm">
                        <thead class="border-b border-sand text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_student') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_admission_no') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_class') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_relationship') }}</th>
                                {{-- 11.3 adds this column to the mockup on
                                     purpose: "an operator cannot otherwise see
                                     what a guardian is entitled to". --}}
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_permissions') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_status') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_actions') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($links as $link)
                                @php
                                    $row = $studentRows[$link->student_id] ?? null;
                                    $validity = LinkPresentation::validity($link);
                                    $flags = LinkPresentation::flags($link);
                                    $scopes = LinkPresentation::scopes($link);
                                @endphp
                                <tr wire:key="guardian-link-{{ $link->id }}">
                                    <td class="px-4 py-2.5">
                                        <div class="flex items-center gap-2.5">
                                            <span class="flex h-8 w-8 shrink-0 items-center justify-center rounded-full bg-chrome-light text-xs font-semibold uppercase text-white">
                                                {{ $row === null ? '?' : mb_substr($row['name'], 0, 1) }}
                                            </span>
                                            <span class="truncate font-medium text-charcoal">{{ $row['name'] ?? $notRecorded }}</span>
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5 font-mono text-xs text-charcoal/80">{{ $row['admission_no'] ?? '—' }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row['class_name'] ?? __('opes.students_screen.no_class') }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">
                                        {{ __('opes.guardians_screen.relationship_'.$link->relationship->value) }}
                                    </td>
                                    <td class="px-4 py-2.5">
                                        @if ($flags === [])
                                            <span class="text-xs text-charcoal/50">{{ __('opes.students_screen.perm_none') }}</span>
                                        @else
                                            <div class="flex flex-wrap gap-1">
                                                @foreach ($flags as $flag)
                                                    <span class="inline-flex items-center rounded-full border border-sand bg-sand/60 px-2 py-0.5 text-xs font-semibold text-charcoal/75">
                                                        {{ __('opes.students_screen.perm_'.$flag) }}
                                                    </span>
                                                @endforeach
                                            </div>
                                        @endif
                                        <div class="mt-1.5 text-xs text-charcoal/55">
                                            <span class="font-medium">{{ __('opes.guardians_screen.effective_scopes') }}:</span>
                                            @if ($scopes === [])
                                                {{ __('opes.guardians_screen.no_effective_scopes') }}
                                            @else
                                                {{ implode(' · ', array_map(fn (string $scope) => __('opes.guardians_screen.scope_'.$scope), $scopes)) }}
                                            @endif
                                        </div>
                                    </td>
                                    <td class="px-4 py-2.5">
                                        <x-status-pill :status="$validityTone[$validity]"
                                                        :label="__('opes.students_screen.validity_'.$validity)"/>
                                        @if ($row !== null)
                                            <div class="mt-1">
                                                <x-status-pill :status="$studentStatusTone[$row['status']] ?? 'amber'"
                                                                :label="__('opes.students_screen.status_'.$row['status'])"/>
                                            </div>
                                        @endif
                                    </td>
                                    <td class="px-4 py-2.5 text-right">
                                        <a href="{{ route('students.show', $link->student_id) }}"
                                           title="{{ __('opes.guardians_screen.view_student') }}"
                                           class="inline-flex rounded p-1.5 text-charcoal/50 hover:bg-sand hover:text-primary">
                                            <span class="sr-only">{{ __('opes.guardians_screen.view_student') }}</span>
                                            <svg class="h-4 w-4" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                                        </a>
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Meetings (7.8). The table exists; nothing writes to it in Phase 2,
         so this is a REAL empty state, not a placeholder grid. --}}
    @if ($tab === 'meetings')
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.guardians_screen.meetings_heading') }}
            </h2>

            @if ($meetings->isEmpty())
                <x-empty-state :message="__('opes.guardians_screen.meetings_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[40rem] border-collapse text-sm">
                        <thead class="border-b border-sand text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_scheduled_at') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_meeting_type') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_meeting_status') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_follow_up') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($meetings as $meeting)
                                <tr wire:key="guardian-meeting-{{ $meeting->id }}">
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $meeting->scheduled_at->translatedFormat('d M Y H:i') }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.meeting_type_'.$meeting->meeting_type->value) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.meeting_status_'.$meeting->status->value) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.follow_up_'.$meeting->follow_up_status->value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Communication history (7.8). Same reasoning as Meetings. --}}
    @if ($tab === 'communications')
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.guardians_screen.communications_heading') }}
            </h2>

            @if ($communications->isEmpty())
                <x-empty-state :message="__('opes.guardians_screen.communications_empty')"/>
            @else
                <div class="min-w-0 overflow-x-auto rounded border border-sand bg-white">
                    <table class="w-full min-w-[44rem] border-collapse text-sm">
                        <thead class="border-b border-sand text-left">
                            <tr class="bg-chrome text-white">
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_sent_at') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_channel') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_direction') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_subject') }}</th>
                                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">{{ __('opes.guardians_screen.column_delivery_status') }}</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-sand">
                            @foreach ($communications as $communication)
                                <tr wire:key="guardian-comm-{{ $communication->id }}">
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ $communication->sent_at?->translatedFormat('d M Y H:i') ?? $notRecorded }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.channel_'.$communication->channel->value) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.direction_'.$communication->direction->value) }}</td>
                                    <td class="px-4 py-2.5 text-charcoal">{{ $communication->subject ?? $notRecorded }}</td>
                                    <td class="px-4 py-2.5 text-charcoal/80">{{ __('opes.guardians_screen.delivery_'.$communication->delivery_status->value) }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif

    {{-- ── Portal access (Phase 12, docs/plans/phase-12-13.md 12.2). The
         activation CODE is shown exactly once, here, after issuing: only its
         SHA-256 is stored, and 00-core 9.3 assumes no SMTP - the office hands
         the code over on paper or over the counter. --}}
    @if ($tab === 'portal')
        <section class="space-y-3">
            <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
                {{ __('opes.guardians_screen.portal_heading') }}
            </h2>

            @if ($issuedCode !== null)
                <div class="rounded border border-primary/40 bg-primary/5 p-4">
                    <p class="text-sm font-semibold text-charcoal">{{ __('opes.guardians_screen.portal_code_heading') }}</p>
                    <p class="mt-2 font-mono text-2xl tracking-widest text-primary">{{ $issuedCode }}</p>
                    <p class="mt-2 text-xs text-charcoal/60">{{ __('opes.guardians_screen.portal_code_notice') }}</p>
                </div>
            @endif

            <div class="rounded border border-sand bg-white p-4">
                @if ($guardian->portal_user_id !== null)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-charcoal">{{ __('opes.guardians_screen.portal_account_active') }}</p>
                            <p class="mt-1 text-xs text-charcoal/60">{{ $portalUserEmail ?? $notRecorded }}</p>
                        </div>
                        <x-status-pill status="ok" :label="__('opes.guardians_screen.portal_pill_active')"/>
                    </div>
                @elseif ($openInvitation !== null)
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <div>
                            <p class="text-sm font-medium text-charcoal">{{ __('opes.guardians_screen.portal_invitation_open') }}</p>
                            <p class="mt-1 text-xs text-charcoal/60">
                                {{ __('opes.guardians_screen.portal_invitation_expires', ['date' => $openInvitation->expires_at->translatedFormat('d M Y H:i')]) }}
                            </p>
                        </div>
                        @if ($canManagePortal)
                            <div class="flex items-center gap-2">
                                <button type="button" wire:click="issuePortalInvitation"
                                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/50">
                                    {{ __('opes.guardians_screen.portal_reissue_button') }}
                                </button>
                                <button type="button" wire:click="revokePortalInvitation({{ $openInvitation->id }})"
                                        class="rounded border border-red-200 px-3 py-1.5 text-sm font-medium text-red-700 hover:bg-red-50">
                                    {{ __('opes.guardians_screen.portal_revoke_button') }}
                                </button>
                            </div>
                        @endif
                    </div>
                @else
                    <div class="flex flex-wrap items-center justify-between gap-3">
                        <p class="text-sm text-charcoal/70">{{ __('opes.guardians_screen.portal_account_none') }}</p>
                        @if ($canManagePortal && ! $guardian->is_archived && $guardian->isActive())
                            <button type="button" wire:click="issuePortalInvitation"
                                    class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                                {{ __('opes.guardians_screen.portal_issue_button') }}
                            </button>
                        @endif
                    </div>
                @endif
            </div>
        </section>
    @endif
</div>
