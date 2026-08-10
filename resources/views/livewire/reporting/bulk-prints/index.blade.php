@php
    /**
     * Bulk Prints (docs/specs/10-documents.md §18). Single root element.
     * Literal English strings: lang/en|fr/opes.php is concurrently edited and
     * this screen deliberately adds no keys to it.
     */
@endphp

<div class="space-y-4">
    @if (session('status'))
        <p class="rounded border border-primary/40 bg-primary/10 p-2 text-sm text-primary" role="status">
            {{ session('status') }}
        </p>
    @endif

    @if (session('error'))
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-2 text-sm text-heritage-red" role="alert">
            {{ session('error') }}
        </p>
    @endif

    <header class="flex flex-wrap items-baseline justify-between gap-2">
        <h1 class="text-lg font-semibold text-charcoal">Bulk Prints</h1>
        <p class="text-xs text-charcoal/60">
            Every document is produced through the document platform's single render path, and each subject
            is its own transaction: one failure marks that student failed, never the batch.
        </p>
    </header>

    @if ($canQueue)
        <section class="rounded border border-border-primary bg-white p-4" aria-label="Queue a bulk print">
            <h2 class="mb-3 text-sm font-semibold text-charcoal">Queue a run</h2>

            <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Document</span>
                    <select wire:model="templateCode" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        @foreach ($templates as $template)
                            <option value="{{ $template->code }}">{{ $template->code }} — {{ $template->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Academic year</span>
                    <select wire:model.live="academicYearId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        @foreach ($academicYears as $year)
                            <option value="{{ $year->id }}">{{ $year->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Class group</span>
                    <select wire:model="classGroupId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="">All classes</option>
                        @foreach ($classGroups as $group)
                            <option value="{{ $group->id }}">{{ $group->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Assessment period</span>
                    <select wire:model="assessmentPeriodId" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="">—</option>
                        @foreach ($assessmentPeriods as $period)
                            <option value="{{ $period->id }}">{{ $period->name }}</option>
                        @endforeach
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Mode</span>
                    <select wire:model="mode" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="all">Print all</option>
                        <option value="unprinted">Print unprinted only</option>
                    </select>
                </label>

                <label class="flex flex-col gap-1">
                    <span class="text-xs font-medium text-charcoal/70">Language</span>
                    <select wire:model="language" class="rounded border border-border-primary bg-white px-2 py-1.5 text-sm">
                        <option value="en">English</option>
                        <option value="fr">Français</option>
                    </select>
                </label>
            </div>

            <p class="mt-3 text-xs text-charcoal/60">
                A report card is printed from its published snapshot, never re-derived from marks, so a period
                that has not been published prints nothing and says so.
            </p>

            @if ($notYetDriveable !== [])
                <p class="mt-1 text-xs text-charcoal/60">
                    Marked bulk-printable but not yet driveable in batch: {{ implode(', ', $notYetDriveable) }}.
                    Their payload is assembled by their own module's print Action, which has no batch entry point
                    yet; they are printed one at a time from their own screens.
                </p>
            @endif

            <div class="mt-3">
                <button type="button" wire:click="queueJob" wire:loading.attr="disabled"
                        class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                    Queue and run
                </button>
            </div>
        </section>
    @endif

    <section class="rounded border border-border-primary bg-white" aria-label="Bulk print jobs">
        <h2 class="border-b border-border-primary px-4 py-3 text-sm font-semibold text-charcoal">Recent jobs</h2>

        @if ($jobs->isEmpty())
            <p class="px-4 py-6 text-sm text-charcoal/60">
                No bulk print has ever been queued on this system.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-charcoal/60">
                        <tr>
                            <th class="px-4 py-2">Job</th>
                            <th class="px-4 py-2">Document</th>
                            <th class="px-4 py-2">Mode</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Progress</th>
                            <th class="px-4 py-2">Requested</th>
                            <th class="px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($jobs as $job)
                            <tr class="border-t border-border-primary/60">
                                <td class="px-4 py-2">#{{ $job->id }}</td>
                                <td class="px-4 py-2">{{ $job->template?->code ?? '—' }} v{{ $job->template_version }}</td>
                                <td class="px-4 py-2">{{ $job->mode }}</td>
                                <td class="px-4 py-2">{{ $job->status }}</td>
                                <td class="px-4 py-2">
                                    {{ $job->succeeded }} / {{ $job->total }}
                                    @if ($job->failed > 0)
                                        <span class="text-heritage-red">({{ $job->failed }} failed)</span>
                                    @endif
                                </td>
                                <td class="px-4 py-2">{{ $job->requested_at?->diffForHumans() ?? '—' }}</td>
                                <td class="px-4 py-2">
                                    <button type="button" wire:click="toggleJob({{ $job->id }})"
                                            class="text-primary underline">
                                        {{ $expandedJobId === $job->id ? 'Hide documents' : 'Documents' }}
                                    </button>
                                    @if ($canQueue && $job->isRetryable())
                                        <button type="button" wire:click="retry({{ $job->id }})"
                                                class="ml-2 text-primary underline">Retry</button>
                                    @endif
                                </td>
                            </tr>

                            @if ($expandedJobId === $job->id)
                                <tr class="border-t border-border-primary/60 bg-sand/20">
                                    <td colspan="7" class="px-4 py-3">
                                        @php($documents = $expandedDocuments)

                                        @if ($documents === [])
                                            <p class="text-sm text-charcoal/60">This job produced no document.</p>
                                        @else
                                            <ul class="space-y-1 text-sm">
                                                @foreach ($documents as $i => $document)
                                                    <li>
                                                        <button type="button"
                                                                wire:click="download({{ $job->id }}, {{ $i }})"
                                                                class="text-primary underline">
                                                            {{ $document['serial'] ?? 'document' }}
                                                        </button>
                                                        @if ($document['is_duplicate'])
                                                            <span class="text-xs text-heritage-red">DUPLICATA</span>
                                                        @endif
                                                    </li>
                                                @endforeach
                                            </ul>
                                        @endif
                                    </td>
                                </tr>
                            @endif
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>
</div>
