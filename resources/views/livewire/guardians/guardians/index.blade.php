@php
    $statusTone = [
        'active' => 'ok',
        'inactive' => 'amber',
        'deceased' => 'red',
    ];
@endphp

<div class="space-y-4">

@if (session('status'))
    <div class="rounded border border-primary/30 bg-primary/5 px-4 py-2.5 text-sm text-charcoal">
        {{ session('status') }}
    </div>
@endif

<div class="flex justify-end">
    <button type="button" wire:click="toggleCreateForm"
            class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
        {{ $showCreateForm ? 'Cancel' : '+ Add Guardian' }}
    </button>
</div>

@if ($showCreateForm)
    <form wire:submit.prevent="saveGuardian" class="space-y-3 rounded border border-border-primary bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">New Guardian</h2>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">First name</span>
                <input type="text" wire:model="createFirstName" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                @error('createFirstName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Last name</span>
                <input type="text" wire:model="createLastName" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                @error('createLastName') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Gender</span>
                <select wire:model="createGender" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                    <option value="male">Male</option>
                    <option value="female">Female</option>
                </select>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Phone</span>
                <input type="text" wire:model="createPhone" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                @error('createPhone') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Alternative phone</span>
                <input type="text" wire:model="createAlternativePhone" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Email</span>
                <input type="email" wire:model="createEmail" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Date of birth</span>
                <input type="date" wire:model="createDateOfBirth" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">ID number (optional)</span>
                <input type="text" wire:model="createIdNumber" placeholder="National ID / passport"
                       class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                @error('createIdNumber') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" wire:click="toggleCreateForm" class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/50">
                Cancel
            </button>
            <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                Create Guardian
            </button>
        </div>
    </form>
@endif

@if ($showLinkForm)
    <form wire:submit.prevent="saveLink" class="space-y-3 rounded border border-border-primary bg-white p-4">
        <h2 class="text-sm font-semibold uppercase tracking-wide text-charcoal/70">
            Link {{ $linkGuardianLabel }} to a Student
        </h2>

        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Student admission no.</span>
                <input type="text" wire:model="linkStudentAdmissionNo" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                @error('linkStudentAdmissionNo') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
            <label class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Relationship</span>
                <select wire:model="linkRelationship" class="rounded border border-border-primary px-2 py-1.5 text-sm">
                    @foreach ($relationshipCases as $case)
                        <option value="{{ $case->value }}">{{ ucwords(str_replace('_', ' ', $case->value)) }}</option>
                    @endforeach
                </select>
                @error('linkRelationship') <span class="text-xs text-heritage-red">{{ $message }}</span> @enderror
            </label>
            @if ($linkRelationship === 'other')
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Describe relationship</span>
                    <input type="text" wire:model="linkRelationshipOther" class="rounded border border-border-primary px-2 py-1.5 text-sm"/>
                </label>
            @endif
        </div>

        <div class="flex flex-wrap gap-4 text-sm text-charcoal/80">
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkHasCustody"/> Custody</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkIsPrimary"/> Primary guardian</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkReceivesReports"/> Receives reports</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkReceivesInvoices"/> Receives invoices</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkIsEmergencyContact"/> Emergency contact</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkIsAuthorisedForPickup"/> Authorised for pickup</label>
            <label class="flex items-center gap-1.5"><input type="checkbox" wire:model="linkIsFeePayer"/> Fee payer</label>
        </div>

        <div class="flex justify-end gap-2">
            <button type="button" wire:click="toggleLinkForm" class="rounded border border-border-primary px-3 py-1.5 text-sm font-medium text-charcoal hover:bg-sand/50">
                Cancel
            </button>
            <button type="submit" class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                Link Guardian
            </button>
        </div>
    </form>
@endif

<x-list-screen
    title="Guardians"
    :breadcrumb="['Dashboard', 'Guardians']"
    :paginator="$rows"
    empty-message="No guardians match these filters yet. Use the Add Guardian button above to create one."
>
    <x-slot:kpis>
        <x-kpi-card label="Total Guardians" :value="$kpis['total']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="No Linked Students" :value="$kpis['orphaned']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="8" r="3.2"/><path stroke-linecap="round" d="M5 20c0-3.5 3.1-6.3 7-6.3s7 2.8 7 6.3"/><path stroke-linecap="round" d="M4 4l16 16"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Portal Activated" :value="$kpis['portal_active']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="5" y="4" width="14" height="16" rx="2"/><path stroke-linecap="round" d="M9 9h6M9 13h6"/><circle cx="12" cy="17" r="0.6" fill="currentColor"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        @if ($relationshipOptions !== [])
            <label for="guardians-filter-relationship" class="flex min-w-[11rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Relationship</span>
                <select id="guardians-filter-relationship" wire:model.live="relationship"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All relationships</option>
                    @foreach ($relationshipOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="guardians-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="guardians-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search name, phone, email, guardian no..."
                   class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal"/>
        </label>
    </x-slot:filters>

    <x-slot:head>
        <tr class="bg-chrome text-white">
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Phone</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Email</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Linked Students</th>
            <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Actions</th>
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="guardian-{{ $row->id }}" class="hover:bg-sand/30">
            <td class="px-4 py-2.5">
                <a href="{{ route('guardians.show', $row->id) }}" class="font-medium text-primary hover:underline">
                    {{ trim($row->first_name.' '.$row->last_name) }}
                </a>
                <p class="text-xs text-charcoal/60">{{ $row->guardian_no }}</p>
            </td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->phone }}</td>
            <td class="px-4 py-2.5 text-charcoal/80">{{ $row->email ?? '—' }}</td>
            <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->students_count }}</td>
            <td class="px-4 py-2.5">
                <x-status-pill :status="$statusTone[$row->status] ?? 'amber'" :label="ucfirst($row->status)"/>
            </td>
            <td class="px-4 py-2.5 text-right">
                <button type="button"
                        wire:click="toggleLinkForm({{ $row->id }}, '{{ addslashes(trim($row->first_name.' '.$row->last_name)) }}')"
                        class="rounded border border-border-primary px-2 py-1 text-xs font-medium text-charcoal hover:bg-sand/50">
                    Link to Student
                </button>
            </td>
        </tr>
    @endforeach

    {{-- Mobile cards. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="guardian-card-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                <a href="{{ route('guardians.show', $row->id) }}" class="flex items-center justify-between gap-2">
                    <p class="font-medium text-primary">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                    <x-status-pill :status="$statusTone[$row->status] ?? 'amber'" :label="ucfirst($row->status)"/>
                </a>
                <p class="mt-1 text-sm text-charcoal/70">{{ $row->guardian_no }} · {{ $row->phone }}</p>
                <p class="mt-1 text-sm text-charcoal/70">{{ $row->students_count }} linked student(s)</p>
            </article>
        @endforeach
    </x-slot:cards>
</x-list-screen>

</div>
