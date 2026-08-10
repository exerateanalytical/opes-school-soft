@php
    /**
     * Status -> pill tone. The WORD carries the meaning (09-ui 10); the
     * colour only reinforces it.
     */
    $staffTone = [
        'active' => 'ok',
        'inactive' => 'amber',
        'on_leave' => 'amber',
        'suspended' => 'amber',
        'terminated' => 'red',
        'retired' => 'red',
    ];

    $staffLabel = [
        'active' => 'Active',
        'inactive' => 'Inactive',
        'on_leave' => 'On leave',
        'suspended' => 'Suspended',
        'terminated' => 'Terminated',
        'retired' => 'Retired',
    ];

    $leaveTone = [
        'draft' => 'amber',
        'submitted' => 'amber',
        'approved' => 'ok',
        'rejected' => 'red',
        'cancelled' => 'red',
        'taken' => 'ok',
    ];

    $leaveLabel = [
        'draft' => 'Draft',
        'submitted' => 'Submitted',
        'approved' => 'Approved',
        'rejected' => 'Rejected',
        'cancelled' => 'Cancelled',
        'taken' => 'Taken',
    ];

    $tabs = [
        ['value' => 'staff', 'label' => 'Staff', 'count' => $tabCounts['staff']],
        ['value' => 'contracts', 'label' => 'Contracts', 'count' => $tabCounts['contracts']],
        ['value' => 'leave', 'label' => 'Leave Requests', 'count' => $tabCounts['leave']],
    ];
@endphp

@if (session('status'))
    <p class="mb-4 rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
        {{ session('status') }}
    </p>
@endif

@error('leave')
    <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
        {{ $message }}
    </p>
@enderror

@foreach (['hire', 'contract', 'leaveRequest', 'terminate'] as $errorBag)
    @error($errorBag)
        <p class="mb-4 rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror
@endforeach

<x-list-screen
    title="Staff Directory"
    :breadcrumb="['Dashboard', 'HR', 'Staff']"
    :paginator="$rows"
    empty-message="No staff records match these filters yet. Staff, contracts and leave requests appear here as they are set up."
    rail-title="Staff Overview"
>
    <x-slot:actions>
        @if ($tab === 'staff')
            <button type="button" wire:click="toggleHireForm"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ $showHireForm ? 'Cancel' : 'Hire Staff Member' }}
            </button>
        @elseif ($tab === 'contracts')
            <button type="button" wire:click="toggleContractForm"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ $showContractForm ? 'Cancel' : 'Open Contract' }}
            </button>
        @elseif ($tab === 'leave')
            <button type="button" wire:click="toggleLeaveForm"
                    class="rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                {{ $showLeaveForm ? 'Cancel' : 'Request Leave' }}
            </button>
        @endif
    </x-slot:actions>

    {{-- Four KPI cards: total staff, active contracts, on leave today,
         pending approvals - dataset-wide numbers. --}}
    <x-slot:kpis>
        <x-kpi-card label="Total Staff" :value="$kpis['total_staff']" icon-bg="bg-primary">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="9" cy="8" r="3"/><circle cx="16.5" cy="9.5" r="2.5"/><path stroke-linecap="round" d="M4 19c0-2.8 2.2-5 5-5s5 2.2 5 5M14.5 14.5c2.5.3 4.5 2.2 4.5 4.5"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Active Contracts" :value="$kpis['active_contracts']" icon-bg="bg-badge-blue">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M5 19V5a2 2 0 012-2h10a2 2 0 012 2v14"/><path stroke-linecap="round" d="M9 7h6M9 11h6M9 15h3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="On Leave Today" :value="$kpis['on_leave_today']" icon-bg="bg-badge-orange">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><rect x="3" y="4" width="18" height="17" rx="2"/><path stroke-linecap="round" d="M3 9h18M8 3v3M16 3v3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
        <x-kpi-card label="Pending Approvals" :value="$kpis['pending_approvals']" icon-bg="bg-badge-purple">
            <x-slot:icon>
                <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 3"/></svg>
            </x-slot:icon>
        </x-kpi-card>
    </x-slot:kpis>

    <x-slot:filters>
        <label for="hr-filter-department" class="flex min-w-[11rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Department</span>
            <select id="hr-filter-department" wire:model.live="department"
                    class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                <option value="">All departments</option>
                @foreach ($departmentOptions as $option)
                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                @endforeach
            </select>
        </label>

        @if ($statusOptions !== [])
            <label for="hr-filter-status" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Status</span>
                <select id="hr-filter-status" wire:model.live="status"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All statuses</option>
                    @foreach ($statusOptions as $option)
                        <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                    @endforeach
                </select>
            </label>
        @endif

        <label for="hr-filter-search" class="flex min-w-[12rem] flex-col gap-1">
            <span class="text-xs font-medium text-charcoal/70">Search</span>
            <input id="hr-filter-search" type="search" wire:model.live.debounce.400ms="search"
                   placeholder="Search name, staff no..."
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
            @if ($tab === 'staff')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Name</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Staff No</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Department</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Position</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Phone</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
            @elseif ($tab === 'contracts')
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Staff</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Role</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Department</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Position</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Starts</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Ends</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Action</th>
            @else
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Staff</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Leave Type</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">From</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">To</th>
                <th scope="col" class="px-4 py-2.5 text-right text-xs font-semibold uppercase tracking-wide">Days</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Action</th>
            @endif
        </tr>
    </x-slot:head>

    @foreach ($rows as $row)
        <tr wire:key="hr-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
            @if ($tab === 'staff')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->staff_no }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->department_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->position_name ?? '—' }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->phone }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$staffTone[$row->status] ?? 'ok'" :label="$staffLabel[$row->status] ?? $row->status"/>
                </td>
            @elseif ($tab === 'contracts')
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 capitalize text-charcoal/80">{{ $row->contract_role }}</td>
                <td class="px-4 py-2.5 uppercase text-charcoal/80">{{ $row->contract_type }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->department_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->position_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->ends_on ?? '—' }}</td>
                <td class="px-4 py-2.5">
                    @if ($row->ends_on === null)
                        <button type="button" wire:click="toggleTerminateForm({{ $row->id }})"
                                class="rounded border border-heritage-red/40 bg-heritage-red/10 px-2 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/20">
                            Terminate
                        </button>
                    @else
                        <span class="text-charcoal/40">—</span>
                    @endif
                </td>
            @else
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->leave_type_name }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->starts_on }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->ends_on }}</td>
                <td class="px-4 py-2.5 text-right tabular-nums">{{ $row->working_days }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$leaveTone[$row->status] ?? 'amber'" :label="$leaveLabel[$row->status] ?? $row->status"/>
                </td>
                <td class="px-4 py-2.5">
                    @if ($row->status === 'submitted')
                        <button type="button" wire:click="approveLeave({{ $row->id }})"
                                wire:confirm="Approve this leave request?"
                                class="rounded border border-primary/40 bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20">
                            Approve
                        </button>
                    @else
                        <span class="text-charcoal/40">—</span>
                    @endif
                </td>
            @endif
        </tr>
    @endforeach

    {{-- Mobile cards: the two or three columns that matter on a handset. --}}
    <x-slot:cards>
        @foreach ($rows as $row)
            <article wire:key="hr-card-{{ $tab }}-{{ $row->id }}"
                     class="rounded border border-border-primary bg-white p-3">
                @if ($tab === 'staff')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$staffTone[$row->status] ?? 'ok'" :label="$staffLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->staff_no }} · {{ $row->department_name ?? 'No department' }} · {{ $row->position_name ?? 'No position' }}</p>
                @elseif ($tab === 'contracts')
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <span class="text-xs uppercase text-charcoal/60">{{ $row->contract_type }}</span>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->department_name }} · {{ $row->position_name }} · since {{ $row->starts_on }}</p>
                    @if ($row->ends_on === null)
                        <button type="button" wire:click="toggleTerminateForm({{ $row->id }})"
                                class="mt-2 rounded border border-heritage-red/40 bg-heritage-red/10 px-2 py-1 text-xs font-medium text-heritage-red hover:bg-heritage-red/20">
                            Terminate
                        </button>
                    @endif
                @else
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ trim($row->first_name.' '.$row->last_name) }}</p>
                        <x-status-pill :status="$leaveTone[$row->status] ?? 'amber'" :label="$leaveLabel[$row->status] ?? $row->status"/>
                    </div>
                    <p class="mt-1 text-sm text-charcoal/70">{{ $row->leave_type_name }} · {{ $row->starts_on }} → {{ $row->ends_on }} · {{ $row->working_days }} days</p>
                    @if ($row->status === 'submitted')
                        <button type="button" wire:click="approveLeave({{ $row->id }})"
                                wire:confirm="Approve this leave request?"
                                class="mt-2 rounded border border-primary/40 bg-primary/10 px-2 py-1 text-xs font-medium text-primary hover:bg-primary/20">
                            Approve
                        </button>
                    @endif
                @endif
            </article>
        @endforeach
    </x-slot:cards>

    {{-- Right rail: kept minimal for this read-only pass. --}}
    <x-slot:rail>
        <div class="space-y-4">
            @if ($showHireForm)
                <section aria-label="Hire staff member" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Hire Staff Member</h3>
                    <div class="space-y-2">
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            First name
                            <input type="text" wire:model="hireFirstName" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Last name
                            <input type="text" wire:model="hireLastName" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Gender
                            <select wire:model="hireGender" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="male">Male</option>
                                <option value="female">Female</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Date of birth
                            <input type="date" wire:model="hireDateOfBirth" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Phone
                            <input type="text" wire:model="hirePhone" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Email (optional)
                            <input type="email" wire:model="hireEmail" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Hired on
                            <input type="date" wire:model="hireHiredOn" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="button" wire:click="saveHire" wire:confirm="Hire this staff member?"
                                class="w-full rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            Save
                        </button>
                    </div>
                </section>
            @endif

            @if ($showContractForm)
                <section aria-label="Open staff contract" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Open Contract</h3>
                    <div class="space-y-2">
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Staff member
                            <select wire:model="contractStaffId" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="">Select…</option>
                                @foreach ($staffOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Contract role
                            <input type="text" wire:model="contractRole" placeholder="e.g. teacher, bursar" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Contract type
                            <select wire:model="contractType" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="cdi">CDI</option>
                                <option value="cdd">CDD</option>
                                <option value="temporaire">Temporaire</option>
                                <option value="occasionnel">Occasionnel</option>
                                <option value="saisonnier">Saisonnier</option>
                                <option value="apprentissage">Apprentissage</option>
                                <option value="stage">Stage</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Working time
                            <select wire:model="contractWorkingTime" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="full_time">Full time</option>
                                <option value="part_time">Part time</option>
                                <option value="hourly">Hourly</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Department
                            <select wire:model="contractDepartmentId" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="">Select…</option>
                                @foreach ($departmentOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Position
                            <select wire:model="contractPositionId" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="">Select…</option>
                                @foreach ($positionOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Starts on
                            <input type="date" wire:model="contractStartsOn" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Ends on (leave blank for CDI)
                            <input type="date" wire:model="contractEndsOn" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="button" wire:click="saveContract" wire:confirm="Open this contract?"
                                class="w-full rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            Save
                        </button>
                    </div>
                </section>
            @endif

            @if ($showLeaveForm)
                <section aria-label="Request leave" class="rounded border border-border-primary bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Request Leave</h3>
                    <div class="space-y-2">
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Contract
                            <select wire:model="leaveStaffContractId" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="">Select…</option>
                                @foreach ($activeContractOptions as $option)
                                    <option value="{{ $option['id'] }}">{{ $option['label'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Leave type
                            <select wire:model="leaveTypeCode" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="">Select…</option>
                                @foreach ($leaveTypeOptions as $option)
                                    <option value="{{ $option['code'] }}">{{ $option['name'] }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            From
                            <input type="date" wire:model="leaveStartsOn" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            To
                            <input type="date" wire:model="leaveEndsOn" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Working days
                            <input type="number" step="0.5" min="0" wire:model="leaveWorkingDays" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="button" wire:click="saveLeaveRequest" wire:confirm="Submit this leave request?"
                                class="w-full rounded border border-primary bg-primary px-3 py-1.5 text-sm font-medium text-white hover:bg-primary/90">
                            Save
                        </button>
                    </div>
                </section>
            @endif

            @if ($terminatingContractId !== null)
                <section aria-label="Terminate contract" class="rounded border border-heritage-red/40 bg-white p-3">
                    <h3 class="mb-2 text-sm font-semibold text-charcoal">Terminate Contract #{{ $terminatingContractId }}</h3>
                    <div class="space-y-2">
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Reason
                            <select wire:model="terminateReason" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal">
                                <option value="resignation">Resignation</option>
                                <option value="licenciement">Licenciement</option>
                                <option value="licenciement_faute_lourde">Licenciement (faute lourde)</option>
                                <option value="fin_cdd">Fin de CDD</option>
                                <option value="retirement">Retirement</option>
                                <option value="death">Death</option>
                                <option value="mutual">Mutual agreement</option>
                            </select>
                        </label>
                        <label class="flex flex-col gap-1 text-xs font-medium text-charcoal/70">
                            Last working day
                            <input type="date" wire:model="terminateLastWorkingDay" class="rounded border border-border-primary px-2 py-1.5 text-sm text-charcoal"/>
                        </label>
                        <button type="button" wire:click="saveTermination" wire:confirm="Terminate this contract? This cannot be undone."
                                class="w-full rounded border border-heritage-red bg-heritage-red px-3 py-1.5 text-sm font-medium text-white hover:bg-heritage-red/90">
                            Confirm Termination
                        </button>
                    </div>
                </section>
            @endif

            <section aria-label="About this directory" class="rounded border border-border-primary bg-white p-3">
                <h3 class="mb-2 text-sm font-semibold text-charcoal">Staff Directory</h3>
                <p class="text-sm text-charcoal/70">Browse staff, their active contracts and leave requests. Submitted leave requests can be approved directly from the Leave Requests tab; hiring and contract changes are still handled elsewhere in HR.</p>
            </section>
        </div>
    </x-slot:rail>
</x-list-screen>
