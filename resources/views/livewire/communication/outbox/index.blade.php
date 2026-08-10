{{-- The Outbox (08-operations 11.1, 09-ui 2/6). No dedicated mockup exists,
     so the chrome mirrors the Phase 10 Transport/Visitors screens exactly:
     KPI strip, filter bar, tabbed table, right rail.

     SINGLE ROOT ELEMENT - Livewire requires it and this repo has broken on
     it before. Everything below lives inside the one <div>. --}}

@php
    $tabs = [
        ['value' => 'queued', 'label' => 'Queued', 'count' => $tabCounts['queued']],
        ['value' => 'sent', 'label' => 'Sent', 'count' => $tabCounts['sent']],
        ['value' => 'failed', 'label' => 'Failed', 'count' => $tabCounts['failed']],
        ['value' => 'disabled', 'label' => 'Not Configured', 'count' => $tabCounts['disabled']],
        ['value' => 'all', 'label' => 'All', 'count' => $tabCounts['all']],
    ];
@endphp

<div class="min-w-0 space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 px-3 py-2 text-sm font-medium text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @error('retry')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 px-3 py-2 text-sm font-medium text-heritage-red" role="alert">
            {{ $message }}
        </p>
    @enderror

    {{-- Detail panel: recipient, channel, rendered body, attempts, last error. --}}
    @if ($selected !== null)
        <section aria-label="Message detail" class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                    <h2 class="text-base font-semibold text-charcoal">
                        Message #{{ $selected->id }} to {{ $selected->recipient }}
                    </h2>
                    <p class="mt-1 text-sm text-charcoal/70">
                        {{ $selected->channel->label() }} ·
                        {{ strtoupper($selected->language) }} ·
                        queued {{ $selected->queued_at->format('Y-m-d H:i') }}
                        @if ($selected->message_template_id !== null)
                            · template {{ $templates[$selected->message_template_id] ?? '#'.$selected->message_template_id }}
                        @else
                            · ad-hoc (no template)
                        @endif
                    </p>
                </div>
                <div class="flex items-center gap-2">
                    <x-status-pill :status="$statusMeta[$selected->status->value]['tone']"
                                   :label="$statusMeta[$selected->status->value]['label']"/>
                    <button type="button" wire:click="showMessage({{ $selected->id }})"
                            class="text-sm font-medium text-charcoal/60 hover:text-charcoal">
                        Close
                    </button>
                </div>
            </div>

            @if ($selected->subject_line !== null)
                <p class="mt-3 text-sm font-medium text-charcoal">Subject: {{ $selected->subject_line }}</p>
            @endif

            <pre class="mt-3 overflow-x-auto whitespace-pre-wrap rounded border border-border-primary bg-chrome/5 p-3 text-sm text-charcoal">{{ $selected->body }}</pre>

            <dl class="mt-3 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-3">
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Attempts</dt>
                    <dd class="text-charcoal">{{ $selected->attempts }} of {{ $maxAttempts }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Sent at</dt>
                    <dd class="text-charcoal">{{ $selected->sent_at?->format('Y-m-d H:i') ?? '—' }}</dd>
                </div>
                <div>
                    <dt class="text-xs font-medium text-charcoal/60">Last error</dt>
                    <dd class="text-charcoal">{{ $selected->failure_reason ?? '—' }}</dd>
                </div>
            </dl>

            @if (is_array($selected->payload) && ($selected->payload['unresolved_variables'] ?? []) !== [])
                <p class="mt-3 rounded border border-heritage-yellow/60 bg-heritage-yellow/20 px-3 py-2 text-sm text-charcoal">
                    Unresolved merge fields:
                    {{ '{'.implode('}, {', $selected->payload['unresolved_variables']).'}' }}.
                    The sender did not supply these values.
                </p>
            @endif

            @if ($selected->status->isRetryable())
                <button type="button" wire:click="retry({{ $selected->id }})"
                        class="mt-4 rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                    Retry this message
                </button>
            @endif
        </section>
    @endif

    <x-list-screen
        title="Outbox"
        :breadcrumb="['Dashboard', 'Communication', 'Outbox']"
        :paginator="$rows"
        empty-message="No messages match these filters. Fee reminders, notices and alerts queued by the system appear here."
        rail-title="Delivery"
    >
        <x-slot:actions>
            <button type="button" wire:click="dispatchQueue"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90">
                Send queued now
            </button>
            <button type="button" wire:click="retryAll"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                Retry all failed
            </button>
            <button type="button" wire:click="exportExcel"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                Excel
            </button>
            <button type="button" wire:click="exportPdf"
                    class="rounded border border-border-primary px-4 py-2 text-sm font-medium text-charcoal/80 hover:text-charcoal">
                PDF
            </button>
        </x-slot:actions>

        <x-slot:kpis>
            <x-kpi-card label="Queued" :value="$kpis['queued']" icon-bg="bg-badge-orange">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M12 7v5l3 2"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Sent" :value="$kpis['sent']" icon-bg="bg-primary">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M22 2L11 13M22 2l-7 20-4-9-9-4 20-7z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Failed" :value="$kpis['failed']" icon-bg="bg-heritage-red">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><circle cx="12" cy="12" r="9"/><path stroke-linecap="round" d="M15 9l-6 6M9 9l6 6"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="Not Configured" :value="$kpis['disabled']" icon-bg="bg-chrome">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M12 9v4m0 4h.01M10.3 3.9L1.8 18a2 2 0 001.7 3h17a2 2 0 001.7-3L13.7 3.9a2 2 0 00-3.4 0z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
            <x-kpi-card label="All Messages" :value="$kpis['total']" icon-bg="bg-badge-blue">
                <x-slot:icon>
                    <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" d="M4 4h16v12H7l-3 3V4z"/></svg>
                </x-slot:icon>
            </x-kpi-card>
        </x-slot:kpis>

        <x-slot:filters>
            <label for="outbox-filter-channel" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Channel</span>
                <select id="outbox-filter-channel" wire:model.live="channel"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All channels</option>
                    @foreach ($channels as $channelCase)
                        <option value="{{ $channelCase->value }}">{{ $channelCase->label() }}</option>
                    @endforeach
                </select>
            </label>

            <label for="outbox-filter-template" class="flex min-w-[10rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Template</span>
                <select id="outbox-filter-template" wire:model.live="template"
                        class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm text-charcoal">
                    <option value="">All templates</option>
                    @foreach ($templates as $templateId => $templateCode)
                        <option value="{{ $templateId }}">{{ $templateCode }}</option>
                    @endforeach
                </select>
            </label>

            <label for="outbox-filter-search" class="flex min-w-[12rem] flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Search</span>
                <input id="outbox-filter-search" type="search" wire:model.live.debounce.400ms="search"
                       placeholder="Recipient, subject or body..."
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
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Queued</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Channel</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Recipient</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Template</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Message</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Attempts</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide">Status</th>
                <th scope="col" class="px-4 py-2.5 text-xs font-semibold uppercase tracking-wide"><span class="sr-only">Actions</span></th>
            </tr>
        </x-slot:head>

        @foreach ($rows as $row)
            <tr wire:key="outbox-{{ $tab }}-{{ $row->id }}" class="hover:bg-sand/30">
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->queued_at->format('Y-m-d H:i') }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->channel->label() }}</td>
                <td class="px-4 py-2.5 font-medium text-charcoal">{{ $row->recipient }}</td>
                <td class="px-4 py-2.5 font-mono text-xs text-charcoal/80">
                    {{ $row->message_template_id === null ? '—' : ($templates[$row->message_template_id] ?? '#'.$row->message_template_id) }}
                </td>
                <td class="max-w-[20rem] truncate px-4 py-2.5 text-charcoal/80">{{ $row->body }}</td>
                <td class="px-4 py-2.5 text-charcoal/80">{{ $row->attempts }}/{{ $maxAttempts }}</td>
                <td class="px-4 py-2.5">
                    <x-status-pill :status="$statusMeta[$row->status->value]['tone']"
                                   :label="$statusMeta[$row->status->value]['label']"/>
                </td>
                <td class="px-4 py-2.5 text-right">
                    <button type="button" wire:click="showMessage({{ $row->id }})"
                            class="text-sm font-medium text-primary hover:underline">
                        Detail
                    </button>
                    @if ($row->status->isRetryable())
                        <button type="button" wire:click="retry({{ $row->id }})"
                                class="ml-3 text-sm font-medium text-primary hover:underline">
                            Retry
                        </button>
                    @endif
                </td>
            </tr>
        @endforeach

        <x-slot:cards>
            @foreach ($rows as $row)
                <article wire:key="outbox-card-{{ $tab }}-{{ $row->id }}" class="rounded border border-border-primary bg-white p-3">
                    <div class="flex items-center justify-between gap-2">
                        <p class="font-medium text-charcoal">{{ $row->recipient }}</p>
                        <x-status-pill :status="$statusMeta[$row->status->value]['tone']"
                                       :label="$statusMeta[$row->status->value]['label']"/>
                    </div>
                    <p class="mt-1 line-clamp-2 text-sm text-charcoal/70">{{ $row->body }}</p>
                    <p class="mt-1 text-xs text-charcoal/60">
                        {{ $row->channel->label() }} · {{ $row->queued_at->format('Y-m-d H:i') }} ·
                        {{ $row->attempts }}/{{ $maxAttempts }} attempts
                    </p>
                    <div class="mt-2 flex items-center gap-3">
                        <button type="button" wire:click="showMessage({{ $row->id }})"
                                class="text-sm font-medium text-primary hover:underline">Detail</button>
                        @if ($row->status->isRetryable())
                            <button type="button" wire:click="retry({{ $row->id }})"
                                    class="text-sm font-medium text-primary hover:underline">Retry</button>
                        @endif
                    </div>
                </article>
            @endforeach
        </x-slot:cards>

        <x-slot:rail>
            <div class="space-y-3 text-sm">
                <p class="text-charcoal/70">
                    Messages are delivered by the scheduled dispatcher
                    (<code class="font-mono text-xs">opes:outbox:dispatch</code>).
                    Until a gateway is configured for a channel, its messages
                    stay visible here as "Not Configured" - nothing is lost,
                    and a retry drains them once the channel is live.
                </p>
                <dl class="space-y-1">
                    <div class="flex items-center justify-between">
                        <dt class="text-charcoal/60">Waiting to go out</dt>
                        <dd class="font-semibold text-charcoal">{{ $kpis['queued'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-charcoal/60">Needing attention</dt>
                        <dd class="font-semibold text-charcoal">{{ $kpis['failed'] + $kpis['disabled'] }}</dd>
                    </div>
                    <div class="flex items-center justify-between">
                        <dt class="text-charcoal/60">Attempt cap</dt>
                        <dd class="font-semibold text-charcoal">{{ $maxAttempts }}</dd>
                    </div>
                </dl>
            </div>
        </x-slot:rail>
    </x-list-screen>
</div>
