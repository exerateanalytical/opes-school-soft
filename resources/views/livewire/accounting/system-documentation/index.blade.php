<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.system_doc_screen.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('opes.system_doc_screen.intro') }}</p>
        </div>
        <button type="button" wire:click="generate" wire:loading.attr="disabled"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white disabled:opacity-60">
            {{ __('opes.system_doc_screen.generate') }}
        </button>
    </header>

    @if ($message !== '')
        <p class="rounded border border-primary/40 bg-primary/10 p-3 text-sm text-primary" role="status">{{ $message }}</p>
    @endif

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    <section class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.generated') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.software_version') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.schema_version') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.system_doc_screen.hash') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.books_screen.supersedes') }}</th>
            </tr>
            </thead>
            <tbody>
            @forelse ($snapshots as $snapshot)
                <tr class="border-t border-sand">
                    <td class="p-2 whitespace-nowrap">{{ $snapshot->generated_at?->format('Y-m-d H:i') }}</td>
                    <td class="p-2">{{ $snapshot->software_version }}</td>
                    <td class="p-2 font-mono text-xs">{{ mb_substr($snapshot->schema_version, -30) }}</td>
                    <td class="p-2 font-mono text-xs">{{ substr($snapshot->sha256, 0, 16) }}…</td>
                    <td class="p-2">{{ $snapshot->supersedes_id ? '#'.$snapshot->supersedes_id : '—' }}</td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-center text-slate-500">{{ __('opes.system_doc_screen.empty') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>
</div>
