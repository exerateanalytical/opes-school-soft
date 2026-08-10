<div class="space-y-6">
    <header class="flex flex-wrap items-center justify-between gap-2">
        <div>
            <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.webhooks_screen.title') }}</h1>
            <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('opes.webhooks_screen.intro') }}</p>
        </div>
        <button type="button" wire:click="$set('showForm', true)"
                class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
            {{ __('opes.webhooks_screen.register') }}
        </button>
    </header>

    @if ($error !== '')
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">{{ $error }}</p>
    @endif

    @if ($revealedSecret !== '')
        <div class="rounded border border-heritage-yellow/60 bg-heritage-yellow/10 p-4">
            <p class="text-sm font-semibold text-charcoal">{{ __('opes.webhooks_screen.secret_shown_once') }}</p>
            <code class="mt-2 block break-all rounded bg-white p-2 text-xs">{{ $revealedSecret }}</code>
        </div>
    @endif

    <x-opes-modal-form wire-model="showForm" :open="$showForm" title="{{ __('opes.webhooks_screen.register') }}">
        <div class="grid gap-3">
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.webhooks_screen.name') }}</span>
                <input type="text" wire:model="name" class="mt-1 w-full rounded border border-sand p-2">
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.webhooks_screen.url') }}</span>
                <input type="text" wire:model="url" placeholder="https://…" class="mt-1 w-full rounded border border-sand p-2">
            </label>
            <label class="text-sm">
                <span class="block text-slate-600">{{ __('opes.webhooks_screen.events') }}</span>
                <input type="text" wire:model="eventsInput" placeholder="fee.invoice_issued, fee.payment_received"
                       class="mt-1 w-full rounded border border-sand p-2">
                <span class="mt-1 block text-xs text-slate-500">{{ __('opes.webhooks_screen.events_hint') }}</span>
            </label>
        </div>

        <div class="mt-3 flex gap-2">
            <button type="button" wire:click="register" class="rounded bg-primary px-4 py-2 text-sm font-semibold text-white">
                {{ __('opes.webhooks_screen.register') }}
            </button>
            <button type="button" wire:click="$set('showForm', false)" class="rounded border border-sand px-4 py-2 text-sm">
                {{ __('opes.homework_screen.cancel') }}
            </button>
        </div>
    </x-opes-modal-form>

    <section class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
        <table class="min-w-full text-sm">
            <thead class="bg-sand/40">
            <tr>
                <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.name') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.url') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.events') }}</th>
                <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.status') }}</th>
                <th class="p-2"></th>
            </tr>
            </thead>
            <tbody>
            @forelse ($endpoints as $endpoint)
                <tr class="border-t border-sand">
                    <td class="p-2">{{ $endpoint->name }}</td>
                    <td class="p-2 font-mono text-xs">{{ $endpoint->url }}</td>
                    <td class="p-2 text-xs">{{ implode(', ', $endpoint->events) }}</td>
                    <td class="p-2">
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $endpoint->is_active ? 'bg-heritage-green text-white' : 'bg-sand text-charcoal' }}">
                            {{ $endpoint->is_active ? __('opes.webhooks_screen.active') : __('opes.webhooks_screen.revoked') }}
                        </span>
                    </td>
                    <td class="p-2 text-right">
                        @if ($endpoint->is_active)
                            <button type="button" wire:click="revoke({{ $endpoint->id }})"
                                    wire:confirm="{{ __('opes.webhooks_screen.confirm_revoke') }}"
                                    class="text-xs font-medium text-heritage-red hover:underline">
                                {{ __('opes.webhooks_screen.revoke') }}
                            </button>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td colspan="5" class="p-4 text-center text-slate-500">{{ __('opes.webhooks_screen.no_endpoints') }}</td></tr>
            @endforelse
            </tbody>
        </table>
    </section>

    <section>
        <h2 class="mb-2 text-sm font-semibold text-charcoal">{{ __('opes.webhooks_screen.recent_deliveries') }}</h2>
        <div class="overflow-x-auto rounded-lg border border-sand bg-white shadow-sm">
            <table class="min-w-full text-sm">
                <thead class="bg-sand/40">
                <tr>
                    <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.name') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.events') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.status') }}</th>
                    <th class="p-2 text-right font-semibold">{{ __('opes.webhooks_screen.attempts') }}</th>
                    <th class="p-2 text-left font-semibold">{{ __('opes.webhooks_screen.response') }}</th>
                </tr>
                </thead>
                <tbody>
                @forelse ($deliveries as $delivery)
                    <tr class="border-t border-sand">
                        <td class="p-2">{{ $delivery->endpoint_name }}</td>
                        <td class="p-2 text-xs">{{ $delivery->event }}</td>
                        <td class="p-2">{{ $delivery->status->value }}</td>
                        <td class="p-2 text-right font-mono">{{ $delivery->attempts }}</td>
                        <td class="p-2 font-mono text-xs">{{ $delivery->response_code ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-4 text-center text-slate-500">{{ __('opes.webhooks_screen.no_deliveries') }}</td></tr>
                @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
