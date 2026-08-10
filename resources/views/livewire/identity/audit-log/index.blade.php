{{--
    Audit Log viewer (09-ui 8.11). Single root element - Livewire 4 requires
    exactly one, and this repo has broken on that more than once.
--}}
<div class="min-w-0 space-y-4">

    {{-- ── Chain verification result ────────────────────────────────────── --}}
    @if ($chainVerified)
        @if ($chainIntact)
            <p role="status"
               class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary">
                Chain intact — {{ number_format($chainChecked) }} entries verified against the anchor.
            </p>
        @else
            <p role="alert"
               class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red">
                Chain BROKEN after {{ number_format($chainChecked) }} entries.
                @if ($chainBrokenId !== null)
                    First broken link: entry #{{ $chainBrokenId }}.
                @endif
                {{ $chainReason }}
            </p>
        @endif
    @endif

    <x-list-screen
        title="Audit Log"
        :breadcrumb="['Identity', 'Audit Log']"
        :paginator="$rows"
        empty-message="No audit entries match the current filters."
    >
        <x-slot:actions>
            <button type="button" wire:click="verifyChain"
                    class="print:hidden rounded border border-primary px-3 py-1.5 text-sm font-semibold text-primary hover:bg-primary/10">
                Verify chain integrity
            </button>

            @if ($canExport)
                <button type="button" wire:click="exportExcel"
                        class="print:hidden rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export Excel
                </button>
                <button type="button" wire:click="exportPdf"
                        class="print:hidden rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                    Export PDF
                </button>
                <button type="button" onclick="window.print()"
                        class="print:hidden rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    Print
                </button>
            @endif
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="Total entries" :value="number_format($kpis['entries'])"/>
            <x-kpi-card label="Distinct actors" :value="$kpis['actors']"/>
            <x-kpi-card label="Modules covered" :value="$kpis['modules']"/>
            <x-kpi-card label="Entries today" :value="$kpis['today']"/>
            <x-kpi-card label="Last entry" :value="$kpis['last_entry'] !== '' ? $kpis['last_entry'] : null"/>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="audit-from" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">From</span>
                <input id="audit-from" type="date" wire:model.live="from"
                       class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
            </label>

            <label for="audit-to" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">To</span>
                <input id="audit-to" type="date" wire:model.live="to"
                       class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
            </label>

            <label for="audit-module" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Module</span>
                <select id="audit-module" wire:model.live="module"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All modules</option>
                    @foreach ($moduleOptions as $option)
                        <option value="{{ $option }}">{{ $option }}</option>
                    @endforeach
                </select>
            </label>

            <label for="audit-action" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Action</span>
                <select id="audit-action" wire:model.live="action"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All actions</option>
                    @foreach ($actionOptions as $option)
                        <option value="{{ $option }}">{{ ucfirst(str_replace('_', ' ', $option)) }}</option>
                    @endforeach
                </select>
            </label>

            <label for="audit-actor" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Actor</span>
                <select id="audit-actor" wire:model.live="actor"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All actors</option>
                    @foreach ($actorOptions as $option)
                        <option value="{{ $option['id'] }}">{{ $option['name'] }}</option>
                    @endforeach
                </select>
            </label>

            <label for="audit-type" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Subject type</span>
                <select id="audit-type" wire:model.live="auditableType"
                        class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
                    <option value="">All subjects</option>
                    @foreach ($typeOptions as $option)
                        <option value="{{ $option }}">{{ \App\Modules\Identity\Livewire\AuditLog\Index::shortType($option) }}</option>
                    @endforeach
                </select>
            </label>

            <label for="audit-search" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="audit-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Actor, subject or IP"
                       class="rounded border border-sand bg-white px-3 py-1.5 text-sm text-charcoal focus:border-primary/50">
            </label>
        </x-slot:filters>

        <x-slot:head>
            <tr>
                <th class="px-3 py-2 font-medium text-charcoal/70">ID</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">When</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Actor</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Action</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Module</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Subject</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">IP</th>
                <th class="px-3 py-2 font-medium text-charcoal/70">Detail</th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $entry)
            <tr class="{{ $selectedId === $entry->id ? 'bg-sand/30' : '' }}">
                <td class="px-3 py-2 font-mono text-xs">{{ $entry->id }}</td>
                <td class="whitespace-nowrap px-3 py-2">{{ $entry->created_at?->format('Y-m-d H:i:s') ?? '-' }}</td>
                <td class="px-3 py-2">{{ $entry->actor_name_at_time }}</td>
                <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $entry->action)) }}</td>
                <td class="px-3 py-2">{{ $entry->module }}</td>
                <td class="px-3 py-2">
                    {{ \App\Modules\Identity\Livewire\AuditLog\Index::shortType($entry->auditable_type) }}
                    @if ($entry->auditable_id !== null)
                        <span class="text-charcoal/50">#{{ $entry->auditable_id }}</span>
                    @endif
                </td>
                <td class="px-3 py-2">{{ $entry->ip ?? '-' }}</td>
                <td class="px-3 py-2">
                    <button type="button" wire:click="toggle({{ $entry->id }})"
                            class="print:hidden rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ $selectedId === $entry->id ? 'Hide' : 'View changes' }}
                    </button>
                </td>
            </tr>

            @if ($selectedId === $entry->id)
                <tr>
                    <td colspan="8" class="bg-sand/20 px-3 py-3">
                        @include('livewire.identity.audit-log._detail', ['entry' => $entry, 'diff' => $diff])
                    </td>
                </tr>
            @endif
        @endforeach

        <x-slot:cards>
            @foreach ($rows as $entry)
                <article class="rounded border border-sand bg-white p-3">
                    <p class="font-medium text-charcoal">
                        #{{ $entry->id }} · {{ ucfirst(str_replace('_', ' ', $entry->action)) }} · {{ $entry->module }}
                    </p>
                    <p class="text-sm text-charcoal/70">
                        {{ $entry->actor_name_at_time }} · {{ $entry->created_at?->format('Y-m-d H:i') ?? '-' }}
                    </p>
                    <p class="text-sm text-charcoal/70">
                        {{ \App\Modules\Identity\Livewire\AuditLog\Index::shortType($entry->auditable_type) }}@if ($entry->auditable_id !== null) #{{ $entry->auditable_id }}@endif
                    </p>
                    <button type="button" wire:click="toggle({{ $entry->id }})"
                            class="print:hidden mt-2 rounded border border-sand px-2 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                        {{ $selectedId === $entry->id ? 'Hide changes' : 'View changes' }}
                    </button>

                    @if ($selectedId === $entry->id)
                        <div class="mt-2">
                            @include('livewire.identity.audit-log._detail', ['entry' => $entry, 'diff' => $diff])
                        </div>
                    @endif
                </article>
            @endforeach
        </x-slot:cards>
    </x-list-screen>
</div>

@once
    <style>
        @media print {
            nav, header, aside, .print\:hidden {
                display: none !important;
            }
        }
    </style>
@endonce
