<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.system_doc_screen.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-charcoal/70">{{ __('opes.system_doc_screen.intro') }}</p>
        </div>
        {{-- The only disable on this screen is transient, and it says why:
             the tooltip is always present and a live message renders beside it. --}}
        <div class="flex items-center gap-3">
            <span class="text-sm text-charcoal/70" wire:loading wire:target="generate" role="status">
                {{ __('opes.system_doc_screen.generating') }}
            </span>
            <button type="button" wire:click="generate" wire:loading.attr="disabled" wire:target="generate"
                    title="{{ __('opes.system_doc_screen.generating') }}"
                    class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
                {{ __('opes.system_doc_screen.generate') }}
            </button>
        </div>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <section class="overflow-x-auto rounded-lg border border-border-primary bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.generated') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.software_version') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.schema_version') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.hash') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.supersedes') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($snapshots as $snapshot)
                <tr class="border-t border-border-primary">
                    <td class="p-2 whitespace-nowrap">{{ $snapshot->generated_at?->format('Y-m-d H:i') }}</td>
                    <td class="p-2">{{ $snapshot->software_version }}</td>
                    <td class="p-2 font-mono text-xs">{{ mb_substr($snapshot->schema_version, -30) }}</td>
                    <td class="p-2 font-mono text-xs" title="{{ $snapshot->sha256 }}">{{ substr($snapshot->sha256, 0, $hashPrefix) }}…</td>
                    <td class="p-2">{{ $snapshot->supersedes_id ? '#'.$snapshot->supersedes_id : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-center text-charcoal/60">{{ __('opes.system_doc_screen.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    {{-- The list is capped. Say so, rather than letting the operator read a
         truncated register as the whole register. --}}
    @if ($snapshotTotal > $listLimit)
        <p class="text-xs text-charcoal/60">
            {{ __('opes.system_doc_screen.showing', ['shown' => $listLimit, 'total' => $snapshotTotal]) }}
        </p>
    @endif
</div>
