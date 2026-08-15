@php
    /**
     * Type/status -> pill tone and label. The WORD carries the meaning
     * (09-ui 10); the colour only reinforces it.
     */
    $typeLabel = [
        'club' => 'Club',
        'sport' => 'Sport',
        'event' => 'Event',
        'excursion' => 'Excursion',
    ];

    // x-status-pill knows ok | amber | red only; the WORD is the signal.
    // Excursion gets amber because it is the one type that carries a
    // consent obligation.
    $typeTone = [
        'club' => 'ok',
        'sport' => 'ok',
        'event' => 'ok',
        'excursion' => 'amber',
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

<x-list-screen
    title="Extra-Curricular Activities"
    :breadcrumb="['Dashboard', 'Activities']"
    :paginator="$rows"
    empty-message="No activities match these filters yet. Clubs, sports teams, events and excursions appear here as they are created."
    rail-title="Activities Overview"
>
    <x-slot:actions>
        @if ($canManage)
            <button type="button" wire:click="toggleCreateForm"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                {{ $showCreateForm ? 'Hide form' : 'New activity' }}
            </button>
        @endif
    </x-slot:actions>

    {{-- The four KPI cards: active activities, total members, sessions
         this week, pending consents - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Active Activities" :value="$kpis['active_activities']" tone="green">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 3l2.4 4.9 5.4.8-3.9 3.8.9 5.4-4.8-2.5-4.8 2.5.9-5.4L4.2 8.7l5.4-.8L12 3z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Total Members" :value="$kpis['total_members']" tone="blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Sessions This Week" :value="$kpis['sessions_this_week']" tone="amber">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="4" y="5" width="16" height="15" rx="2"/><path stroke-linecap="round" d="M8 3v4M16 3v4M4 10h16"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Pending Consents" :value="$kpis['pending_consents']" tone="pink">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M9 12l2 2 4-4M12 21a9 9 0 110-18 9 9 0 010 18z"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="activities-filter-type" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Type</span>
            <select id="activities-filter-type" wire:model.live="type"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All types</option>
                <option value="club">Club</option>
                <option value="sport">Sport</option>
                <option value="event">Event</option>
                <option value="excursion">Excursion</option>
            </select>
        </label>

        <label for="activities-filter-status" class="flex min-w-[10rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Status</span>
            <select id="activities-filter-status" wire:model.live="status"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All statuses</option>
                <option value="active">Active</option>
                <option value="closed">Closed</option>
            </select>
        </label>

        <label for="activities-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="activities-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search name, venue, destination..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Activity</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Venue / Destination</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Members</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Sessions</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Next Session</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Pending Consents</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="activity-{{ $row->id }}" class="hover:bg-sand/30">
            {{-- The row click actually goes to the detail page - detail
                 pages being the platform's known weakness, this link IS
                 the deliverable. --}}
            <td class="px-4 py-2.5">
                <a href="{{ url('/activities/'.$row->id) }}" class="font-medium text-primary hover:underline">{{ $row->name }}</a>
            </td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$typeTone[$row->type] ?? 'ok'" :label="$typeLabel[$row->type] ?? $row->type"/>
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->type === 'excursion' ? ($row->destination ?? '—') : ($row->venue ?? '—') }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->members_count }}{{ $row->capacity !== null ? ' / '.$row->capacity : '' }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->sessions_count }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->next_session_on ?? '—' }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">
                @if ((int) $row->pending_consents > 0)
                    <span class="font-semibold text-heritage-red">{{ $row->pending_consents }}</span>
                @else
                    <span class="text-charcoal/50">0</span>
                @endif
            </td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Closed'"/>
            </td>
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="activity-card-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                <div class="flex items-center justify-between gap-2">
                    <a href="{{ url('/activities/'.$row->id) }}" class="font-medium text-primary hover:underline">{{ $row->name }}</a>
                    <x-status-pill :status="$row->status === 'active' ? 'ok' : 'amber'" :label="$row->status === 'active' ? 'Active' : 'Closed'"/>
                </div>
                <p class="mt-1 text-sm text-charcoal/70">
                    {{ $typeLabel[$row->type] ?? $row->type }} · {{ $row->members_count }} members · {{ $row->sessions_count }} sessions
                    @if ((int) $row->pending_consents > 0)
                        · <span class="font-semibold text-heritage-red">{{ $row->pending_consents }} consents pending</span>
                    @endif
                </p>
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: the create form (manage only) above the type
         breakdown. --}}
    <x-slot:rail>
        <div class="space-y-4">
            @if ($canManage && $showCreateForm)
                <section aria-label="New activity" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">New Activity</h3>

                    <form wire:submit="saveActivity" class="space-y-3">
                        <label for="create-form-name" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Name</span>
                            <input id="create-form-name" type="text" wire:model="createFormName"
                                   placeholder="e.g. Chess Club"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('createFormName')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="create-form-type" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Type</span>
                            <select id="create-form-type" wire:model.live="createFormType"
                                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                                <option value="club">Club</option>
                                <option value="sport">Sport</option>
                                <option value="event">Event</option>
                                <option value="excursion">Excursion</option>
                            </select>
                            @error('createFormType')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="create-form-venue" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Venue</span>
                            <input id="create-form-venue" type="text" wire:model="createFormVenue"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('createFormVenue')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <label for="create-form-capacity" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Capacity (optional)</span>
                            <input id="create-form-capacity" type="number" min="1" wire:model="createFormCapacity"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('createFormCapacity')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        @if ($createFormType === 'excursion')
                            <label for="create-form-destination" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">Destination</span>
                                <input id="create-form-destination" type="text" wire:model="createFormDestination"
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                                @error('createFormDestination')
                                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                                @enderror
                            </label>

                            <label for="create-form-departure" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">Departure</span>
                                <input id="create-form-departure" type="datetime-local" wire:model="createFormDepartureAt"
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                                @error('createFormDepartureAt')
                                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                                @enderror
                            </label>

                            <label for="create-form-return" class="flex flex-col gap-1">
                                <span class="text-xs font-medium text-charcoal/70">Return</span>
                                <input id="create-form-return" type="datetime-local" wire:model="createFormReturnAt"
                                       class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                                @error('createFormReturnAt')
                                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif

                        <label for="create-form-description" class="flex flex-col gap-1">
                            <span class="text-xs font-medium text-charcoal/70">Description</span>
                            <input id="create-form-description" type="text" wire:model="createFormDescription"
                                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
                            @error('createFormDescription')
                                <span class="text-xs text-heritage-red">{{ $message }}</span>
                            @enderror
                        </label>

                        <button type="submit"
                                class="w-full rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                            Create activity
                        </button>
                    </form>
                </section>
            @endif

            <section aria-label="Programme breakdown" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Active by Type</h3>
                <ul class="space-y-1.5">
                    @foreach ($typeBreakdown as $band)
                        <li class="flex items-center justify-between text-sm">
                            <span class="text-charcoal/70">{{ $typeLabel[$band['type']] ?? $band['type'] }}</span>
                            <span class="tabular-nums font-medium text-charcoal">{{ $band['count'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
</div>
