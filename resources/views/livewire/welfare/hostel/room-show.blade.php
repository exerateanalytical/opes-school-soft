{{-- One room's occupancy file — /hostel/rooms/{room}. Header summary and
     occupancy KPIs, the current occupants (with class group and primary
     guardian contact), the bed roster, the inspection record, then the full
     current + historical bed allocations for every bed in the room, plus a
     printable Room Card (Assets\Livewire\Show's asset-card pattern).
     Heritage design system, PROGRESS.md §4a Phase 2. --}}

@php
    $ratingTone = [
        'good' => 'ok',
        'fair' => 'amber',
        'poor' => 'red',
        'critical' => 'red',
    ];
@endphp

<div class="min-w-0 space-y-4">
    <nav aria-label="{{ __('opes.ui.breadcrumb') }}">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>{{ __('opes.welfare_detail.breadcrumb_dashboard') }}</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/hostel') }}" class="hover:text-primary">{{ __('opes.welfare_detail.breadcrumb_hostel') }}</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $room->hostel_code }} / {{ $room->name }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-2xl font-bold text-charcoal">{{ $room->hostel_code }} — {{ $room->name }}</h1>
            <span class="inline-flex items-center rounded-full border border-badge-blue/40 bg-badge-blue/10 px-2.5 py-0.5 text-xs font-semibold text-badge-blue">
                {{ __('opes.welfare_detail.gender.'.$room->gender) }}
            </span>
            @unless ($room->hostel_is_active)
                <x-status-pill status="amber" :label="__('opes.welfare_detail.hostel_inactive')"/>
            @endunless
        </div>
        <a href="{{ url('/hostel') }}"
           class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
            {{ __('opes.welfare_detail.back_to_hostel') }}
        </a>
    </div>

    {{-- ── Occupancy KPIs ─────────────────────────────────────────────── --}}
    <div class="grid grid-cols-2 gap-3 lg:grid-cols-5">
        @foreach ([
            ['label' => __('opes.welfare_detail.kpi_capacity'), 'value' => $room->capacity],
            ['label' => __('opes.welfare_detail.kpi_beds'), 'value' => $stats['beds']],
            ['label' => __('opes.welfare_detail.kpi_occupied'), 'value' => $stats['occupied']],
            ['label' => __('opes.welfare_detail.kpi_free'), 'value' => $stats['free']],
            ['label' => __('opes.welfare_detail.kpi_occupancy_rate'), 'value' => $stats['occupancy_pct'].'%'],
        ] as $kpi)
            <div class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <p class="text-xs uppercase tracking-wide text-charcoal/50">{{ $kpi['label'] }}</p>
                <p class="mt-1 text-2xl font-bold tabular-nums text-charcoal">{{ $kpi['value'] }}</p>
            </div>
        @endforeach
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Room card ──────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.room_details') }}</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-4 px-4 py-4 sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_hostel') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $room->hostel_name }} ({{ $room->hostel_code }})</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_warden') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $room->warden_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_capacity') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.beds_count', ['count' => $room->capacity]) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_gender') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ __('opes.welfare_detail.gender.'.$room->gender) }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_created_at') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $room->created_at ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_updated_at') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $room->updated_at ?? '—' }}</dd>
                    </div>
                </dl>
            </div>

            {{-- ── Current occupants ──────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.current_occupants') }}</h2>
                </div>
                @if ($occupants->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_occupants')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_bed') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_student') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_class') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_since') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_primary_guardian') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($occupants as $occupant)
                                    <tr wire:key="occupant-{{ $occupant->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $occupant->bed_label }}</td>
                                        <td class="px-4 py-2 text-charcoal">
                                            <a href="{{ url('/students/'.$occupant->student_id) }}" class="font-medium hover:text-primary">
                                                {{ trim($occupant->first_name.' '.$occupant->last_name) }}
                                            </a>
                                            <span class="block text-xs text-charcoal/60">{{ $occupant->matricule }}</span>
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $occupant->class_group ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $occupant->starts_on }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">
                                            @if ($occupant->guardian_first_name !== null)
                                                {{ trim($occupant->guardian_first_name.' '.$occupant->guardian_last_name) }}
                                                <span class="block text-xs text-charcoal/60">{{ $occupant->guardian_phone ?? '—' }}</span>
                                            @else
                                                —
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Beds ───────────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.beds_title') }}</h2>
                </div>
                @if ($beds->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_beds')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_bed') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_active') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_occupancy') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($beds as $bed)
                                    <tr wire:key="bed-{{ $bed->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $bed->label }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$bed->is_active ? 'ok' : 'red'"
                                                           :label="$bed->is_active ? __('opes.welfare_detail.active') : __('opes.welfare_detail.inactive')"/>
                                        </td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$bed->occupied > 0 ? 'amber' : 'ok'"
                                                           :label="$bed->occupied > 0 ? __('opes.welfare_detail.occupied') : __('opes.welfare_detail.free')"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Inspections ────────────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.inspections_title') }}</h2>
                </div>
                @if ($inspections->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_inspections')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_date') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_scope') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_rating') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_findings') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_inspector') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_resolved') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($inspections as $inspection)
                                    <tr wire:key="inspection-{{ $inspection->id }}">
                                        <td class="px-4 py-2 text-charcoal/70">{{ $inspection->inspected_on }}</td>
                                        <td class="px-4 py-2 text-xs text-charcoal/60">
                                            {{ $inspection->room_id === null
                                                ? __('opes.welfare_detail.scope_hostel_wide')
                                                : __('opes.welfare_detail.scope_this_room') }}
                                        </td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$ratingTone[$inspection->rating] ?? 'amber'"
                                                           :label="__('opes.welfare_detail.rating.'.$inspection->rating)"/>
                                        </td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $inspection->findings ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $inspection->inspector_name ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $inspection->resolved_at ?? '—' }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Allocation history ─────────────────────────────────── --}}
            <div class="rounded-xl border border-border-primary bg-white shadow-sm">
                <div class="border-b border-border-primary px-4 py-3">
                    <h2 class="text-base font-semibold text-charcoal">{{ __('opes.welfare_detail.allocation_history') }}</h2>
                </div>
                @if ($allocationHistory->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state :message="__('opes.welfare_detail.no_allocations')"/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-border-primary text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_bed') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_student') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_matricule') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_starts') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_ends') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_allocated_by') }}</th>
                                    <th scope="col" class="px-4 py-2">{{ __('opes.welfare_detail.col_status') }}</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border-primary bg-white">
                                @foreach ($allocationHistory as $alloc)
                                    <tr wire:key="alloc-{{ $alloc->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $alloc->bed_label }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ trim($alloc->first_name.' '.$alloc->last_name) }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->matricule }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->starts_on }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->ends_on ?? '—' }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->allocated_by_name ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$alloc->status === 'active' ? 'ok' : 'amber'"
                                                           :label="$alloc->status === 'active' ? __('opes.welfare_detail.active') : __('opes.welfare_detail.ended')"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>
        </div>

        {{-- ── Right rail: printable Room Card ────────────────────────── --}}
        <div class="min-w-0 space-y-4">
            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm print:border-0 print:shadow-none" id="room-card-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.print_room_card') }}</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded-xl border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            {{ __('opes.welfare_detail.print') }}
                        </button>
                        <button type="button" wire:click="exportRoomCardPdf"
                                class="rounded-xl bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            {{ __('opes.welfare_detail.export_pdf') }}
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_room') }}</dt>
                        <dd class="mt-0.5 text-base font-medium text-charcoal">{{ $room->hostel_code }} — {{ $room->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.field_warden') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">{{ $room->warden_name ?? '—' }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.beds_title') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal">
                            {{ __('opes.welfare_detail.beds_occupied_summary', ['beds' => $stats['beds'], 'occupied' => $stats['occupied']]) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">{{ __('opes.welfare_detail.kpi_occupancy_rate') }}</dt>
                        <dd class="mt-0.5 text-base text-charcoal tabular-nums">{{ $stats['occupancy_pct'] }}%</dd>
                    </div>
                </dl>
            </section>

            {{-- Billing linkage is not modelled for hostel beds (no fee_item
                 FK on hostels/rooms/allocations) - stated honestly rather
                 than faked. PROGRESS.md rule 11. --}}
            <section class="rounded-xl border border-border-primary bg-white p-4 shadow-sm">
                <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">{{ __('opes.welfare_detail.billing_title') }}</h2>
                <p class="mt-2 text-sm text-charcoal/70">{{ __('opes.welfare_detail.hostel_billing_note') }}</p>
            </section>
        </div>
    </div>
</div>
