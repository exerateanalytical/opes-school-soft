<div class="min-w-0 space-y-4">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.messages_title') }}</h1>

    @if ($threads === [])
        <x-empty-state :message="__('opes.guardian_portal.messages_empty')"/>
    @else
        <ul class="divide-y divide-border-secondary rounded border border-border-primary bg-white shadow-sm">
            @foreach ($threads as $thread)
                <li wire:key="thread-{{ $thread['id'] }}">
                    <a href="{{ route('portal.messages.thread', $thread['id']) }}"
                       class="flex items-start gap-3 px-4 py-3 hover:bg-surface-secondary">
                        <span class="flex h-9 w-9 shrink-0 items-center justify-center rounded-full bg-chrome text-xs font-semibold uppercase text-white">
                            {{ mb_substr($thread['title'], 0, 1) }}
                        </span>

                        <span class="min-w-0 flex-1">
                            <span @class([
                                'block truncate text-sm text-charcoal',
                                'font-semibold' => $thread['unread_count'] > 0,
                                'font-medium' => $thread['unread_count'] === 0,
                            ])>{{ $thread['title'] }}</span>

                            @if ($thread['last_message_at'])
                                <span class="block text-xs text-charcoal/50">{{ $thread['last_message_at'] }}</span>
                            @endif
                        </span>

                        @if ($thread['unread_count'] > 0)
                            <span class="shrink-0 rounded-full bg-danger px-2 py-0.5 text-xs font-semibold text-white">
                                {{ $thread['unread_count'] }}
                            </span>
                        @endif
                    </a>
                </li>
            @endforeach
        </ul>
    @endif
</div>
