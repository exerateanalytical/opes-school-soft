<div class="min-w-0 space-y-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <h1 class="text-xl font-semibold text-charcoal">
            {{ __('opes.guardian_portal.notifications_title') }}
            @if ($unread > 0)
                <span class="ml-1 rounded-full bg-danger px-2 py-0.5 align-middle text-xs font-semibold text-white">{{ $unread }}</span>
            @endif
        </h1>

        @if ($unread > 0)
            <button type="button" wire:click="markAllRead"
                    class="rounded border border-border-primary px-3 py-1.5 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                {{ __('opes.guardian_portal.notifications_mark_all') }}
            </button>
        @endif
    </div>

    @if (session('portal-status'))
        <p class="rounded border border-success/30 bg-success-bg px-4 py-2 text-sm text-success">{{ session('portal-status') }}</p>
    @endif

    @if ($notifications->isEmpty())
        <x-empty-state :message="__('opes.guardian_portal.notifications_empty')"/>
    @else
        <ul class="space-y-2">
            @foreach ($notifications as $notification)
                <li wire:key="notif-{{ $notification->id }}"
                    @class([
                        'rounded border bg-white p-4 shadow-sm',
                        'border-heritage-yellow/50' => $notification->read_at === null,
                        'border-border-primary' => $notification->read_at !== null,
                    ])>
                    <div class="flex items-start justify-between gap-3">
                        <div class="min-w-0">
                            <p @class(['text-sm text-charcoal', 'font-semibold' => $notification->read_at === null])>
                                {{ $notification->title }}
                            </p>
                            @if ($notification->body)
                                <p class="mt-1 text-sm text-charcoal/70">{{ $notification->body }}</p>
                            @endif
                            <p class="mt-2 text-xs text-charcoal/50">{{ $notification->created_at }}</p>
                        </div>

                        @if ($notification->read_at === null)
                            <button type="button" wire:click="markRead({{ $notification->id }})"
                                    class="shrink-0 rounded border border-border-primary px-2.5 py-1 text-xs font-medium text-charcoal hover:border-primary/50 hover:text-primary">
                                {{ __('opes.guardian_portal.notifications_mark_all') }}
                            </button>
                        @endif
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
