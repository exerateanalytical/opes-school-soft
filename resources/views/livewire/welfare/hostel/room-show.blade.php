{{-- One room's occupancy file — /hostel/rooms/{room}. Header summary,
     bed roster, then the current + historical bed allocations for every
     bed in the room, plus a printable Room Card (Assets\Livewire\Show's
     asset-card pattern). --}}

<div class="min-w-0 space-y-4">
    <nav aria-label="Breadcrumb">
        <ol class="flex flex-wrap items-center gap-1 text-xs text-charcoal/60">
            <li>Dashboard</li>
            <li aria-hidden="true">/</li>
            <li><a href="{{ url('/hostel') }}" class="hover:text-primary">Hostel</a></li>
            <li aria-hidden="true">/</li>
            <li aria-current="page" class="font-medium text-charcoal/80">{{ $room->hostel_code }} / {{ $room->name }}</li>
        </ol>
    </nav>

    <div class="flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3">
            <h1 class="text-xl font-semibold text-charcoal">{{ $room->hostel_code }} — {{ $room->name }}</h1>
            <span class="inline-flex items-center rounded-full border border-badge-blue/40 bg-badge-blue/10 px-2.5 py-0.5 text-xs font-semibold text-badge-blue capitalize">
                {{ $room->gender }}
            </span>
        </div>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="min-w-0 space-y-4 xl:col-span-2">
            {{-- ── Room card ──────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Room details</h2>
                </div>
                <dl class="grid grid-cols-1 gap-x-6 gap-y-3 px-4 py-4 text-sm sm:grid-cols-2">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Hostel</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $room->hostel_name }} ({{ $room->hostel_code }})</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Capacity</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $room->capacity }} beds</dd>
                    </div>
                </dl>
            </div>

            {{-- ── Beds ───────────────────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Beds</h2>
                </div>
                @if ($beds->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No beds registered for this room."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Bed</th>
                                    <th scope="col" class="px-4 py-2">Active</th>
                                    <th scope="col" class="px-4 py-2">Occupied</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($beds as $bed)
                                    <tr wire:key="bed-{{ $bed->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $bed->label }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$bed->is_active ? 'ok' : 'red'" :label="$bed->is_active ? 'Active' : 'Inactive'"/>
                                        </td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$bed->occupied > 0 ? 'amber' : 'ok'" :label="$bed->occupied > 0 ? 'Occupied' : 'Free'"/>
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            {{-- ── Allocation history ─────────────────────────────────── --}}
            <div class="rounded border border-sand bg-white">
                <div class="border-b border-sand px-4 py-3">
                    <h2 class="text-sm font-semibold text-charcoal">Allocation history</h2>
                </div>
                @if ($allocationHistory->isEmpty())
                    <div class="px-4 py-6">
                        <x-empty-state message="No allocations recorded for this room."/>
                    </div>
                @else
                    <div class="overflow-x-auto">
                        <table class="min-w-full divide-y divide-sand text-sm">
                            <thead class="bg-sand/40 text-left text-xs uppercase tracking-wide text-charcoal/60">
                                <tr>
                                    <th scope="col" class="px-4 py-2">Bed</th>
                                    <th scope="col" class="px-4 py-2">Student</th>
                                    <th scope="col" class="px-4 py-2">Matricule</th>
                                    <th scope="col" class="px-4 py-2">Starts</th>
                                    <th scope="col" class="px-4 py-2">Ends</th>
                                    <th scope="col" class="px-4 py-2">Status</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-sand bg-white">
                                @foreach ($allocationHistory as $alloc)
                                    <tr wire:key="alloc-{{ $alloc->id }}">
                                        <td class="px-4 py-2 font-medium text-charcoal">{{ $alloc->bed_label }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ trim($alloc->first_name.' '.$alloc->last_name) }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->matricule }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->starts_on }}</td>
                                        <td class="px-4 py-2 text-charcoal/70">{{ $alloc->ends_on ?? '—' }}</td>
                                        <td class="px-4 py-2">
                                            <x-status-pill :status="$alloc->status === 'active' ? 'ok' : 'amber'" :label="$alloc->status === 'active' ? 'Active' : 'Ended'"/>
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
            <section class="rounded border border-sand bg-white p-4 print:border-0 print:shadow-none" id="room-card-section">
                <div class="flex items-center justify-between gap-3 print:hidden">
                    <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">Print room card</h2>
                    <div class="flex items-center gap-2">
                        <button type="button" onclick="window.print()"
                                class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                            Print
                        </button>
                        <button type="button" wire:click="exportRoomCardPdf"
                                class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                            Export PDF
                        </button>
                    </div>
                </div>

                <dl class="mt-3 space-y-3 text-sm">
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Room</dt>
                        <dd class="mt-0.5 font-medium text-charcoal">{{ $room->hostel_code }} — {{ $room->name }}</dd>
                    </div>
                    <div>
                        <dt class="text-xs uppercase tracking-wide text-charcoal/50">Beds</dt>
                        <dd class="mt-0.5 text-charcoal">{{ $beds->count() }} ({{ $beds->sum(fn ($b) => (int) $b->occupied) }} occupied)</dd>
                    </div>
                </dl>
            </section>
        </div>
    </div>
</div>
