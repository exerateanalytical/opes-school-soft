{{-- Medical desk (09-ui §Medical dashboard). No dedicated mockup exists, so
     the chrome mirrors the Phase 10 Transport/Hostel screens exactly:
     KPI strip, filter bar, tabbed table, right rail. Clinical text shown
     here was decrypted by the model casts; the raw columns hold ciphertext. --}}

@php
    $severityTone = ['low' => 'ok', 'moderate' => 'amber', 'high' => 'red'];
    $severityLabel = ['low' => 'Low', 'moderate' => 'Moderate', 'high' => 'High'];

    $outcomeTone = [
        'returned_to_class' => 'ok',
        'sent_home' => 'amber',
        'referred' => 'amber',
        'admitted' => 'red',
    ];
    $outcomeLabel = [
        'returned_to_class' => 'Returned to class',
        'sent_home' => 'Sent home',
        'referred' => 'Referred',
        'admitted' => 'Admitted',
    ];

    $tabs = [
        ['value' => 'consultations', 'label' => 'Consultations', 'count' => $tabCounts['consultations']],
        ['value' => 'referrals', 'label' => 'Referrals', 'count' => $tabCounts['referrals']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    {{-- Inline record-consultation panel (nurse desk; no separate route). --}}
    @if ($showForm && $canManage)
        <section aria-label="Record consultation" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Record Consultation</h2>

            <form wire:submit="saveConsultation" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="medical-form-matricule" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Student matricule</span>
                        <input id="medical-form-matricule" type="text" wire:model="formMatricule"
                               placeholder="e.g. OS-26-A1B2C3D4"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formMatricule')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="medical-form-visited" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Visited at</span>
                        <input id="medical-form-visited" type="datetime-local" wire:model="formVisitedAt"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="medical-form-complaint" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Presenting complaint</span>
                        <textarea id="medical-form-complaint" rows="2" wire:model="formComplaint"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                        @error('formComplaint')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="medical-form-diagnosis" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Diagnosis (optional)</span>
                        <textarea id="medical-form-diagnosis" rows="2" wire:model="formDiagnosis"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>

                    <label for="medical-form-treatment" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Treatment (optional)</span>
                        <textarea id="medical-form-treatment" rows="2" wire:model="formTreatment"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>

                    <label for="medical-form-severity" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Severity</span>
                        <select id="medical-form-severity" wire:model="formSeverity"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($severityLabel as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>

                    <label for="medical-form-outcome" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Outcome</span>
                        <select id="medical-form-outcome" wire:model="formOutcome"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            @foreach ($outcomeLabel as $value => $label)
                                <option value="{{ $value }}">{{ $label }}</option>
                            @endforeach
                        </select>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Save consultation
                    </button>
                    <button type="button" wire:click="toggleForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline refer panel, opened from a consultation row. --}}
    @if ($referConsultationId !== null && $canManage)
        <section aria-label="Record referral" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Refer Consultation #{{ $referConsultationId }}</h2>

            <form wire:submit="saveReferral" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="medical-refer-to" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Referred to (facility / practitioner)</span>
                        <input id="medical-refer-to" type="text" wire:model="referTo"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('referTo')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="medical-refer-on" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.welfare_detail.referred_on') }}</span>
                        <input id="medical-refer-on" type="date" wire:model="referOn"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('referOn')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="medical-refer-reason" class="flex flex-col gap-1 sm:col-span-2">
                        <span class="text-xs font-medium text-charcoal/70">Clinical reason</span>
                        <textarea id="medical-refer-reason" rows="2" wire:model="referReason"
                                  class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Record referral
                    </button>
                    <button type="button" wire:click="cancelReferral"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    {{-- Inline close-referral panel, opened from a referral row. --}}
    @if ($closeReferralId !== null && $canManage)
        <section aria-label="Close referral" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Close Referral #{{ $closeReferralId }}</h2>

            <form wire:submit="confirmClose" class="mt-4 space-y-4">
                <label for="medical-close-on" class="flex flex-col gap-1 sm:max-w-xs">
                    <span class="text-xs font-medium text-charcoal/70">{{ __('opes.welfare_detail.followed_up_on') }}</span>
                    <input id="medical-close-on" type="date" wire:model="closeFollowedUpOn"
                           class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    @error('closeFollowedUpOn')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <label for="medical-close-notes" class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Follow-up notes (optional)</span>
                    <textarea id="medical-close-notes" rows="2" wire:model="closeNotes"
                              class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"></textarea>
                    @error('closeNotes')
                        <span class="text-xs text-heritage-red">{{ $message }}</span>
                    @enderror
                </label>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Close referral
                    </button>
                    <button type="button" wire:click="cancelClose"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
        title="Medical"
        :breadcrumb="['Dashboard', 'Welfare', 'Medical']"
        :paginator="$rows"
        empty-message="No medical records match these filters yet. Consultations and referrals appear here as the sick bay records them."
        rail-title="Medical Overview"
    >
        <x-slot:actions>
            @if ($canManage)
                <button type="button" wire:click="toggleForm"
                        class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ $showForm ? 'Hide form' : 'Record consultation' }}
                </button>
            @endif
        </x-slot:actions>

        {{-- 09-ui §Medical KPI cards: Today's Visits · Active Treatments ·
             Medical Records · Referrals. --}}
        <x-slot:kpis>
            <x-kpi-card label="Today's Visits" :value="$kpis['today_visits']" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 21s-6.7-4.5-9-9a5.2 5.2 0 019-4.6A5.2 5.2 0 0121 12c-2.3 4.5-9 9-9 9z"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 12h3l1.5-3 2 5L15 12h2"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Active Treatments" :value="$kpis['active_treatments']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="8" width="18" height="12" rx="2"/><path stroke-linecap="round" d="M9 8V5a2 2 0 012-2h2a2 2 0 012 2v3M12 11v6M9 14h6"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Medical Records" :value="$kpis['medical_records']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M6 3h9l4 4v14a1 1 0 01-1 1H6a1 1 0 01-1-1V4a1 1 0 011-1z"/><path stroke-linecap="round" d="M9 12h6M9 16h6M9 8h3"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Open Referrals" :value="$kpis['open_referrals']" icon-bg="bg-heritage-red">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M13 5l7 7-7 7M4 12h16"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            @if ($tab === 'consultations')
                <label for="medical-filter-severity" class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Severity</span>
                    <select id="medical-filter-severity" wire:model.live="severity"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All severities</option>
                        @foreach ($severityLabel as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>

                <label for="medical-filter-outcome" class="flex min-w-[11rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Outcome</span>
                    <select id="medical-filter-outcome" wire:model.live="status"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All outcomes</option>
                        @foreach ($outcomeLabel as $value => $label)
                            <option value="{{ $value }}">{{ $label }}</option>
                        @endforeach
                    </select>
                </label>
            @else
                <label for="medical-filter-state" class="flex min-w-[10rem] flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">State</span>
                    <select id="medical-filter-state" wire:model.live="status"
                            class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                        <option value="">All referrals</option>
                        <option value="open">Open</option>
                        <option value="closed">Closed</option>
                    </select>
                </label>
            @endif

            <label for="medical-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="medical-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search student name, matricule..."
                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
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
                @if ($tab === 'consultations')
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Visited</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Matricule</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Complaint</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Severity</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Outcome</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
                    @endif
                @else
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Referred on</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Student</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Referred to</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Reason</th>
                    <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">State</th>
                    @if ($canManage)
                        <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
                    @endif
                @endif
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="medical-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                @if ($tab === 'consultations')
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->visited_at->format('Y-m-d H:i') }}</td>
                    <td class="px-4 py-2.5 font-medium text-charcoal">{{ $students[$row->student_id]['name'] ?? '—' }}</td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $students[$row->student_id]['matricule'] ?? '—' }}</td>
                    <td class="max-w-[18rem] truncate px-4 py-2.5 text-charcoal/80">{{ $row->presenting_complaint }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$severityTone[$row->severity->value] ?? 'ok'" :label="$severityLabel[$row->severity->value] ?? $row->severity->value"/>
                    </td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$outcomeTone[$row->outcome->value] ?? 'ok'" :label="$outcomeLabel[$row->outcome->value] ?? $row->outcome->value"/>
                    </td>
                    @if ($canManage)
                        <td class="px-4 py-2.5 text-right">
                            <button type="button" wire:click="startReferral({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Refer
                            </button>
                        </td>
                    @endif
                @else
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->referred_on->toDateString() }}</td>
                    <td class="px-4 py-2.5 font-medium text-charcoal">
                        {{ $row->consultation !== null ? ($students[$row->consultation->student_id]['name'] ?? '—') : '—' }}
                    </td>
                    <td class="px-4 py-2.5 text-charcoal/80">{{ $row->referred_to }}</td>
                    <td class="max-w-[18rem] truncate px-4 py-2.5 text-charcoal/80">{{ $row->reason }}</td>
                    <td class="px-4 py-2.5">
                        <x-status-pill :status="$row->followed_up_at === null ? 'amber' : 'ok'"
                                       :label="$row->followed_up_at === null ? 'Open' : 'Closed '.$row->followed_up_at->toDateString()"/>
                    </td>
                    @if ($canManage)
                        <td class="px-4 py-2.5 text-right">
                            @if ($row->followed_up_at === null)
                                <button type="button" wire:click="startClose({{ $row->id }})"
                                        class="text-sm font-medium text-primary hover:underline">
                                    Close
                                </button>
                            @endif
                        </td>
                    @endif
                @endif
            </tr>
        @endforeach

        {{-- Mobile cards: the two or three fields that matter on a handset. --}}
        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="medical-card-{{ $tab }}-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                    @if ($tab === 'consultations')
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-charcoal">{{ $students[$row->student_id]['name'] ?? '—' }}</p>
                            <x-status-pill :status="$severityTone[$row->severity->value] ?? 'ok'" :label="$severityLabel[$row->severity->value] ?? $row->severity->value"/>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->visited_at->format('Y-m-d H:i') }} · {{ $outcomeLabel[$row->outcome->value] ?? $row->outcome->value }}</p>
                    @else
                        <div class="flex items-center justify-between gap-2">
                            <p class="font-medium text-charcoal">
                                {{ $row->consultation !== null ? ($students[$row->consultation->student_id]['name'] ?? '—') : '—' }}
                            </p>
                            <x-status-pill :status="$row->followed_up_at === null ? 'amber' : 'ok'"
                                           :label="$row->followed_up_at === null ? 'Open' : 'Closed'"/>
                        </div>
                        <p class="mt-1 text-sm text-charcoal/70">{{ $row->referred_on->toDateString() }} · {{ $row->referred_to }}</p>
                    @endif
                </article>
            @endforeach
        </x-slot:cards>

        {{-- Right rail: severity bars + recent high-severity alerts, the
             09-ui "Recent Medical Alerts by severity" column. --}}
        <x-slot:rail>
            <div class="space-y-4">
                <section aria-label="Visits by severity" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Visits by Severity (30 days)</h3>
                    @php
                        $severityTotal = array_sum($kpis['severity_breakdown']);
                    @endphp
                    <ul class="space-y-2.5">
                        @foreach ($kpis['severity_breakdown'] as $band => $count)
                            <li>
                                <div class="flex items-center justify-between text-xs text-charcoal/70">
                                    <span>{{ $severityLabel[$band] ?? $band }}</span>
                                    <span class="tabular-nums">{{ $count }}</span>
                                </div>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                    <div class="h-1.5 rounded-full {{ match ($band) {
                                            'low' => 'bg-primary',
                                            'moderate' => 'bg-heritage-yellow',
                                            default => 'bg-heritage-red',
                                        } }}"
                                         style="width: {{ $severityTotal > 0 ? (int) round($count * 100 / $severityTotal) : 0 }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section aria-label="Recent medical alerts" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Recent Medical Alerts</h3>
                    @if ($recentAlerts === [])
                        <p class="text-sm text-charcoal/60">No high-severity visits in the last 30 days.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($recentAlerts as $alert)
                                <li class="flex items-start justify-between gap-2 text-sm">
                                    <div>
                                        <p class="font-medium text-charcoal">{{ $alert['student'] }}</p>
                                        <p class="text-xs text-charcoal/60">{{ $alert['visited_at'] }} · {{ $outcomeLabel[$alert['outcome']] ?? $alert['outcome'] }}</p>
                                    </div>
                                    <x-status-pill status="red" :label="$severityLabel[$alert['severity']] ?? $alert['severity']"/>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </x-slot:rail>
    </x-list-screen>
</div>
