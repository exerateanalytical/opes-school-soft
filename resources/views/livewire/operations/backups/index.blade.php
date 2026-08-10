@php
    /**
     * Backup & Restore (08-operations §3, 09-ui §8.12). Single root element.
     * Literal English strings: lang/en|fr/opes.php is concurrently edited.
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
        <h1 class="text-lg font-semibold text-charcoal">Backup &amp; Restore</h1>
        <p class="text-xs text-charcoal/60">Primary target: <code>{{ $backupPath }}</code></p>
    </header>

    @if ($alerts !== [])
        <ul class="space-y-2" aria-label="Backup health">
            @foreach ($alerts as $alert)
                <li class="rounded border border-heritage-red/40 bg-heritage-red/5 p-3 text-sm">
                    <span class="font-semibold text-charcoal">{{ $alert->label }}</span>
                    <span class="ml-2 uppercase text-xs text-heritage-red">{{ $alert->status->value }}</span>
                    <p class="text-charcoal/80">{{ $alert->detail }}</p>
                    @if ($alert->remedy !== '')
                        <p class="text-charcoal/60">{{ $alert->remedy }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    @endif

    @if ($secondTarget === null)
        <p class="rounded border border-heritage-red/40 bg-heritage-red/5 p-3 text-sm text-charcoal/80">
            Backups are being written to one location only. A backup on the same disk as the database is not a
            backup: the disk that fails takes both.
        </p>
    @endif

    <section class="rounded border border-sand bg-white p-4" aria-label="Backup actions">
        <div class="flex flex-wrap gap-2">
            <button type="button" wire:click="runBackup" wire:loading.attr="disabled"
                    class="rounded bg-primary px-3 py-1.5 text-sm font-medium text-white disabled:opacity-50">
                Run backup now
            </button>
            <button type="button" wire:click="prune" wire:loading.attr="disabled"
                    class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal disabled:opacity-50">
                Apply retention policy
            </button>
            @if ($canRestore)
                <button type="button" wire:click="runDrill" wire:loading.attr="disabled"
                        class="rounded border border-sand px-3 py-1.5 text-sm font-medium text-charcoal disabled:opacity-50">
                    Run restore drill
                </button>
            @endif
        </div>
        <p class="mt-2 text-xs text-charcoal/60">
            A restore drill loads the newest healthy backup into a throwaway schema, asserts it, and drops it
            again. It never touches the live database.
        </p>
    </section>

    <section class="rounded border border-sand bg-white" aria-label="Backups">
        <h2 class="border-b border-sand px-4 py-3 text-sm font-semibold text-charcoal">Backups</h2>

        @if ($backups->isEmpty())
            <p class="px-4 py-6 text-sm text-charcoal/60">
                No backup has ever been taken on this system. Use “Run backup now”.
            </p>
        @else
            <div class="overflow-x-auto">
                <table class="w-full text-left text-sm">
                    <thead class="text-xs uppercase text-charcoal/60">
                        <tr>
                            <th class="px-4 py-2">Taken</th>
                            <th class="px-4 py-2">Kind</th>
                            <th class="px-4 py-2">Status</th>
                            <th class="px-4 py-2">Size</th>
                            <th class="px-4 py-2">Verified</th>
                            <th class="px-4 py-2">Location</th>
                            <th class="px-4 py-2">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($backups as $backup)
                            <tr class="border-t border-sand/60">
                                <td class="px-4 py-2">{{ $backup->completed_at?->diffForHumans() ?? 'running' }}</td>
                                <td class="px-4 py-2">{{ $backup->kind }}</td>
                                <td class="px-4 py-2">
                                    <span class="{{ $backup->status === 'healthy' ? 'text-charcoal' : 'text-heritage-red' }}">
                                        {{ $backup->status }}
                                    </span>
                                    @if ($backup->failure_detail !== null)
                                        <p class="text-xs text-heritage-red">{{ $backup->failure_detail }}</p>
                                    @endif
                                </td>
                                <td class="px-4 py-2">
                                    {{ $backup->size_bytes === null ? '—' : number_format($backup->size_bytes / 1048576, 1).' MB' }}
                                </td>
                                <td class="px-4 py-2">{{ $backup->verified_at?->diffForHumans() ?? 'never' }}</td>
                                <td class="px-4 py-2"><code class="text-xs">{{ $backup->path }}</code></td>
                                <td class="px-4 py-2">
                                    <button type="button" wire:click="verify({{ $backup->id }})"
                                            class="text-primary underline">Verify</button>
                                    @if ($canRestore)
                                        <button type="button" wire:click="prepareRestore({{ $backup->id }})"
                                                class="ml-2 text-heritage-red underline">Restore…</button>
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>
        @endif
    </section>

    @if ($canRestore)
        <section class="rounded border border-heritage-red/40 bg-heritage-red/5 p-4" aria-label="Restore">
            <h2 class="text-sm font-semibold text-charcoal">Restore over the live database</h2>
            <p class="mt-1 text-sm text-charcoal/80">
                A restore overwrites everything recorded since the backup was taken. This screen will not perform
                one: it checks the preconditions and hands you the command to run at the server console.
            </p>

            <label class="mt-3 flex max-w-sm flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">Type RESTORE to unlock</span>
                <input type="text" wire:model="restoreConfirmation"
                       class="rounded border border-sand bg-white px-2 py-1.5 text-sm"/>
            </label>

            @if ($restoreCommand !== null)
                <pre class="mt-3 overflow-x-auto rounded bg-charcoal p-3 text-xs text-white">{{ $restoreCommand }}</pre>
                <button type="button" wire:click="resetRestore" class="mt-2 text-sm text-primary underline">
                    Clear
                </button>
            @endif
        </section>
    @else
        <p class="rounded border border-sand bg-white p-3 text-xs text-charcoal/60">
            Restore is withheld from your role by design, including from Administrator. It is granted deliberately,
            to a named person, for the day it is needed.
        </p>
    @endif

    <section class="rounded border border-sand bg-white" aria-label="Restore drills">
        <h2 class="border-b border-sand px-4 py-3 text-sm font-semibold text-charcoal">Restore drills</h2>

        @if ($drills->isEmpty())
            <p class="px-4 py-6 text-sm text-charcoal/60">
                No restore drill has ever run. Until one passes, “we have backups” is untested.
            </p>
        @else
            <ul class="divide-y divide-sand/60 text-sm">
                @foreach ($drills as $drill)
                    <li class="px-4 py-2">
                        <span class="{{ $drill->status === 'passed' ? 'text-charcoal' : 'text-heritage-red' }}">
                            {{ $drill->status }}
                        </span>
                        <span class="ml-2 text-charcoal/60">
                            {{ $drill->completed_at?->diffForHumans() ?? 'running' }} ·
                            {{ $drill->assertions_passed }} assertions
                        </span>
                        @if ($drill->failure_detail !== null)
                            <p class="text-xs text-heritage-red">{{ $drill->failure_detail }}</p>
                        @endif
                    </li>
                @endforeach
            </ul>
        @endif
    </section>
</div>
