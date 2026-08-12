{{-- `/portal/messages` - built to mobile/messages-inbox.png. --}}
<div class="min-w-0 space-y-5">

    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="chat" tone="primary"/>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.messages_title') }}</h1>
    </div>

    @if ($threads === [])
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="chat" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.messages_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        <x-portal.card :padded="false">
            <div class="divide-y divide-border-secondary">
                @foreach ($threads as $thread)
                    <a wire:key="thread-{{ $thread['id'] }}"
                       href="{{ route('portal.messages.thread', $thread['id']) }}"
                       class="flex items-center gap-3 px-4 py-3 hover:bg-surface-secondary">
                        <x-portal.avatar :name="$thread['title']" tone="green"/>

                        <span class="min-w-0 flex-1">
                            <span @class([
                                'block truncate text-sm text-charcoal',
                                'font-bold' => $thread['unread_count'] > 0,
                                'font-medium' => $thread['unread_count'] === 0,
                            ])>{{ $thread['title'] }}</span>

                            @if ($thread['last_message_at'])
                                <span class="mt-0.5 block truncate text-xs text-charcoal/60">
                                    {{ \Illuminate\Support\Carbon::parse($thread['last_message_at'])->diffForHumans() }}
                                </span>
                            @endif
                        </span>

                        @if ($thread['unread_count'] > 0)
                            <span class="flex h-5 min-w-5 shrink-0 items-center justify-center rounded-full bg-portal-danger px-1.5 text-[11px] font-bold text-white">
                                {{ $thread['unread_count'] }}
                            </span>
                        @endif
                    </a>
                @endforeach
            </div>
        </x-portal.card>
    @endif
</div>
