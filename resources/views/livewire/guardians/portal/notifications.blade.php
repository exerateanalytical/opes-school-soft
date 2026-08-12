{{-- `/portal/notifications` - built to mobile/notifications.png. --}}
<div class="min-w-0 space-y-5">

    <div class="flex flex-wrap items-center gap-3 pt-2">
        <x-portal.icon name="bell" tone="primary"/>

        <div class="min-w-0 flex-1">
            <h1 class="text-2xl font-bold text-charcoal">
                {{ __('opes.guardian_portal.notifications_title') }}
            </h1>
            @if ($unread > 0)
                <p class="text-sm text-charcoal/60">
                    {{ __('opes.guardian_portal.dashboard_unread', ['count' => $unread]) }}
                </p>
            @endif
        </div>

        @if ($unread > 0)
            <button type="button" wire:click="markAllRead"
                    class="shrink-0 rounded-xl border border-primary px-3 py-2 text-xs font-semibold text-primary hover:bg-portal-tint">
                {{ __('opes.guardian_portal.notifications_mark_all') }}
            </button>
        @endif
    </div>

    @if ($notifications->isEmpty())
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="bell" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.notifications_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        <div class="space-y-3">
            @foreach ($notifications as $notification)
                <x-portal.card wire:key="notif-{{ $notification->id }}"
                               :tone="$notification->read_at === null ? 'green' : 'white'">
                    <div class="flex items-start gap-3">
                        <x-portal.icon name="bell"
                                       :tone="$notification->read_at === null ? 'gold' : 'primary'"/>

                        <div class="min-w-0 flex-1">
                            <p @class([
                                'text-sm text-charcoal',
                                'font-bold' => $notification->read_at === null,
                                'font-medium' => $notification->read_at !== null,
                            ])>{{ $notification->title }}</p>

                            @if ($notification->body)
                                <p class="mt-1 text-sm text-charcoal/70">{{ $notification->body }}</p>
                            @endif

                            <p class="mt-2 text-xs text-charcoal/50">{{ $notification->created_at }}</p>
                        </div>

                        @if ($notification->read_at === null)
                            <button type="button" wire:click="markRead({{ $notification->id }})"
                                    class="shrink-0 rounded-lg border border-border-primary bg-white px-2.5 py-1.5 text-xs font-semibold text-primary hover:border-primary/50">
                                {{ __('opes.guardian_portal.notifications_mark_all') }}
                            </button>
                        @endif
                    </div>
                </x-portal.card>
            @endforeach
        </div>
    @endif
</div>
