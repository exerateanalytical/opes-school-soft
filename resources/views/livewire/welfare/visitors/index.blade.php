{{-- Gate desk (phase-10 plan §5: check-in/out desk). No dedicated mockup
     exists, so the chrome mirrors the Phase 10 Transport/Hostel/Medical
     screens exactly: KPI strip, filter bar, tabbed table, right rail. The
     ID document reference shown here was decrypted by the model cast; the
     raw column holds ciphertext. --}}

@php
    $hostTypeLabel = ['staff' => 'Staff', 'student' => 'Student', 'office' => 'Office'];
    $hostTypeTone = ['staff' => 'amber', 'student' => 'ok', 'office' => 'ok'];

    $tabs = [
        ['value' => 'onsite', 'label' => 'On Site', 'count' => $tabCounts['onsite']],
        ['value' => 'register', 'label' => 'Full Register', 'count' => $tabCounts['register']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('checkout')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- Inline check-in panel (gate desk; no separate route). --}}
    @if ($showForm)
        <section aria-label="Check in visitor" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <h2 class="text-base font-semibold text-charcoal">Check In Visitor</h2>

            <form wire:submit="saveCheckIn" class="mt-4 space-y-4">
                <div class="grid grid-cols-1 gap-x-6 gap-y-4 sm:grid-cols-2">
                    <label for="visitor-form-name" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Visitor name</span>
                        <input id="visitor-form-name" type="text" wire:model="formName"
                               placeholder="e.g. Ngwa Franklin"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formName')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="visitor-form-phone" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Phone</span>
                        <input id="visitor-form-phone" type="text" wire:model="formPhone"
                               placeholder="e.g. 6 77 00 00 00"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formPhone')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="visitor-form-idref" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">ID document ref (optional, stored encrypted)</span>
                        <input id="visitor-form-idref" type="text" wire:model="formIdRef"
                               placeholder="National ID / passport no."
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>

                    <label for="visitor-form-purpose" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Purpose of visit</span>
                        <input id="visitor-form-purpose" type="text" wire:model="formPurpose"
                               placeholder="e.g. Fee payment, parent meeting..."
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formPurpose')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="visitor-form-host-type" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Visiting</span>
                        <select id="visitor-form-host-type" wire:model.live="formHostType"
                                class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                            <option value="office">An office / desk</option>
                            <option value="staff">A staff member</option>
                            <option value="student">A student</option>
                        </select>
                    </label>

                    @if ($formHostType !== 'office')
                        <label for="visitor-form-host-ref" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">
                                {{ $formHostType === 'student' ? 'Student matricule' : 'Staff email' }}
                            </span>
                            <input id="visitor-form-host-ref" type="text" wire:model="formHostRef"
                                   placeholder="{{ $formHostType === 'student' ? 'e.g. OS-26-A1B2C3D4' : 'e.g. bursar@school.cm' }}"
                                   class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                            @error('formHostRef')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>
                    @endif

                    <label for="visitor-form-badge" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Badge no.</span>
                        <input id="visitor-form-badge" type="text" wire:model="formBadge"
                               placeholder="e.g. V-07"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formBadge')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="visitor-form-gate-pass" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">{{ __('opes.welfare_detail.gate_pass_optional') }}</span>
                        <input id="visitor-form-gate-pass" type="text" wire:model="formGatePass"
                               placeholder="e.g. GP-2026-001"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                        @error('formGatePass')
                            <span class="text-xs text-heritage-red">{{ $message }}</span>
                        @enderror
                    </label>

                    <label for="visitor-form-checked-in" class="flex flex-col gap-1">
                        <span class="text-xs font-medium text-charcoal/70">Checked in at</span>
                        <input id="visitor-form-checked-in" type="datetime-local" wire:model="formCheckedInAt"
                               class="rounded border border-border-primary bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50"/>
                    </label>
                </div>

                <div class="flex items-center gap-3">
                    <button type="submit"
                            class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                        Check in
                    </button>
                    <button type="button" wire:click="toggleForm"
                            class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/70 hover:text-charcoal">
                        Cancel
                    </button>
                </div>
            </form>
        </section>
    @endif

    <x-list-screen
        title="Visitors"
        :breadcrumb="['Dashboard', 'Welfare', 'Visitors']"
        :paginator="$rows"
        empty-message="No visitors match these filters yet. Check-ins appear here as the gate desk records them."
        rail-title="Gate Overview"
    >
        <x-slot:actions>
            <button type="button" wire:click="toggleForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showForm ? 'Hide form' : 'Check in visitor' }}
            </button>
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="On Site Now" :value="$kpis['on_site']" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 00-4-4H6a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path stroke-linecap="round" stroke-linejoin="round" d="M22 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Today's Visitors" :value="$kpis['today']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="18" rx="2"/><path stroke-linecap="round" d="M16 2v4M8 2v4M3 10h18"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Checked Out Today" :value="$kpis['checked_out_today']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="This Week" :value="$kpis['week']" icon-bg="bg-chrome">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M3 3v18h18"/><path stroke-linecap="round" stroke-linejoin="round" d="M7 15l4-4 3 3 5-6"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="visitor-filter-host" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Visiting</span>
                <select id="visitor-filter-host" wire:model.live="hostType"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All hosts</option>
                    @foreach ($hostTypeLabel as $value => $label)
                        <option value="{{ $value }}">{{ $label }}</option>
                    @endforeach
                </select>
            </label>

            <label for="visitor-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="visitor-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Search name, badge, phone..."
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
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Checked in</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Visitor</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Phone</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Purpose</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Visiting</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Badge</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="visitor-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->checked_in_at->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->visitor_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->phone }}</td>
                <td class="max-w-[16rem] truncate px-4 py-2.5 text-charcoal/80">{{ $row->purpose }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">
                    <span class="mr-1">{{ $hosts[$row->id] ?? $hostTypeLabel[$row->host_type->value] }}</span>
                    <x-status-pill :status="$hostTypeTone[$row->host_type->value] ?? 'ok'" :label="$hostTypeLabel[$row->host_type->value]"/>
                </td>
                <td class="px-4 py-2.5 font-mono text-charcoal/80">{{ $row->badge_no }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$row->checked_out_at === null ? 'amber' : 'ok'"
                                   :label="$row->checked_out_at === null ? 'On site' : 'Left '.$row->checked_out_at->format('H:i')"/>
                </td>
                <td class="px-4 py-2.5 text-right">
                    @if ($row->checked_out_at === null)
                        <div class="flex items-center justify-end gap-2">
                            <label for="visitor-checkout-pass-{{ $row->id }}" class="sr-only">
                                {{ __('opes.welfare_detail.gate_pass_optional') }}
                            </label>
                            <input id="visitor-checkout-pass-{{ $row->id }}" type="text"
                                   wire:model="checkoutGatePass.{{ $row->id }}"
                                   placeholder="{{ __('opes.welfare_detail.gate_pass_short') }}"
                                   class="w-28 rounded border border-border-primary bg-white px-2 py-1 text-xs text-charcoal focus:border-primary/50"/>
                            <button type="button" wire:click="checkOut({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Check out
                            </button>
                        </div>
                    @else
                        <span class="font-mono text-xs text-charcoal/60">{{ $row->gate_pass_no ?? '—' }}</span>
                    @endif
                </td>
            </tr>
        @endforeach

        {{-- Mobile cards: the two or three fields that matter on a handset. --}}
        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="visitor-card-{{ $tab }}-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->visitor_name }}</p>
                        <x-status-pill :status="$row->checked_out_at === null ? 'amber' : 'ok'"
                                       :label="$row->checked_out_at === null ? 'On site' : 'Left'"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">
                        {{ $row->checked_in_at->format('Y-m-d H:i') }} · Badge {{ $row->badge_no }} · {{ $hosts[$row->id] ?? $hostTypeLabel[$row->host_type->value] }}
                    </p>
                    @if ($row->checked_out_at === null)
                        <div class="mt-2 flex items-center gap-2">
                            <label for="visitor-card-pass-{{ $row->id }}" class="sr-only">
                                {{ __('opes.welfare_detail.gate_pass_optional') }}
                            </label>
                            <input id="visitor-card-pass-{{ $row->id }}" type="text"
                                   wire:model="checkoutGatePass.{{ $row->id }}"
                                   placeholder="{{ __('opes.welfare_detail.gate_pass_short') }}"
                                   class="w-28 rounded border border-border-primary bg-white px-2 py-1 text-xs text-charcoal focus:border-primary/50"/>
                            <button type="button" wire:click="checkOut({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">
                                Check out
                            </button>
                        </div>
                    @endif
                </article>
            @endforeach
        </x-slot:cards>

        {{-- Right rail: host-type bars + the oldest open visits. --}}
        <x-slot:rail>
            <div class="space-y-4">
                <section aria-label="Visits by host type" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Visits by Host (30 days)</h3>
                    @php
                        $hostTotal = array_sum($hostBreakdown);
                    @endphp
                    <ul class="space-y-2.5">
                        @foreach ($hostBreakdown as $band => $count)
                            <li>
                                <div class="flex items-center justify-between text-xs text-charcoal/70">
                                    <span>{{ $hostTypeLabel[$band] ?? $band }}</span>
                                    <span class="tabular-nums">{{ $count }}</span>
                                </div>
                                <div class="mt-1 h-1.5 w-full rounded-full bg-sand">
                                    <div class="h-1.5 rounded-full {{ match ($band) {
                                            'staff' => 'bg-heritage-yellow',
                                            'student' => 'bg-primary',
                                            default => 'bg-badge-blue',
                                        } }}"
                                         style="width: {{ $hostTotal > 0 ? (int) round($count * 100 / $hostTotal) : 0 }}%"></div>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>

                <section aria-label="Longest on site" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Longest On Site</h3>
                    @if ($longestOnSite === [])
                        <p class="text-sm text-charcoal/60">Nobody is on site right now.</p>
                    @else
                        <ul class="space-y-2">
                            @foreach ($longestOnSite as $visit)
                                <li class="flex items-start justify-between gap-2 text-sm">
                                    <div>
                                        <p class="font-medium text-charcoal">{{ $visit['visitor'] }}</p>
                                        <p class="text-xs text-charcoal/60">Since {{ $visit['since'] }}</p>
                                    </div>
                                    <x-status-pill status="amber" :label="'Badge '.$visit['badge']"/>
                                </li>
                            @endforeach
                        </ul>
                    @endif
                </section>
            </div>
        </x-slot:rail>
    </x-list-screen>
</div>
