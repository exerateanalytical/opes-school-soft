<div class="relative" wire:poll.30s x-data="{ open: @entangle('open') }">
    <button type="button"
            x-on:click="open = ! open"
            class="relative rounded-full p-2 text-charcoal/70 hover:bg-sand"
            aria-haspopup="true"
            :aria-expanded="open ? 'true' : 'false'">
        <span class="sr-only">{{ __('opes.notifications.bell_label') }}</span>
        <svg class="h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" aria-hidden="true">
            <path stroke-linecap="round" stroke-linejoin="round" d="M18 8a6 6 0 10-12 0c0 7-3 9-3 9h18s-3-2-3-9"/>
            <path stroke-linecap="round" d="M13.73 21a2 2 0 01-3.46 0"/>
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -right-0.5 -top-0.5 flex h-4 min-w-4 items-center justify-center rounded-full bg-heritage-red px-1 text-[10px] font-bold text-white">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div x-show="open"
         x-on:click.outside="open = false"
         x-cloak
         class="absolute right-0 z-40 mt-2 w-80 max-w-[90vw] rounded-lg border border-sand bg-white shadow-lg">
        <div class="flex items-center justify-between border-b border-sand px-3 py-2">
            <span class="text-sm font-semibold text-charcoal">{{ __('opes.notifications.title') }}</span>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead" class="text-xs font-medium text-primary hover:underline">
                    {{ __('opes.notifications.mark_all_read') }}
                </button>
            @endif
        </div>

        <ul class="max-h-96 divide-y divide-sand overflow-y-auto">
            @forelse ($notifications as $notification)
                <li class="{{ $notification->read_at === null ? 'bg-primary/5' : '' }}">
                    <button type="button" wire:click="markRead({{ $notification->id }})"
                            class="w-full px-3 py-2 text-left hover:bg-sand/40">
                        <span class="block text-sm font-medium text-charcoal">{{ $notification->title }}</span>
                        @if ($notification->body)
                            <span class="mt-0.5 block text-xs text-slate-600">{{ $notification->body }}</span>
                        @endif
                        <span class="mt-0.5 block text-[10px] text-slate-400">{{ $notification->created_at->diffForHumans() }}</span>
                    </button>
                </li>
            @empty
                <li class="px-3 py-6 text-center text-sm text-slate-500">{{ __('opes.notifications.empty') }}</li>
            @endforelse
        </ul>
    </div>
</div>
