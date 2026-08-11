<div class="min-w-0 space-y-4">
    <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.guardian_portal.announcements_title') }}</h1>

    @if ($announcements->isEmpty())
        <x-empty-state :message="__('opes.guardian_portal.announcements_empty')"/>
    @else
        <ul class="space-y-3">
            @foreach ($announcements as $announcement)
                @php
                    $isRead = $announcement->first_message_id !== null
                        && (int) ($announcement->last_read_message_id ?? 0) >= (int) $announcement->first_message_id;
                @endphp
                <li wire:key="ann-{{ $announcement->id }}"
                    class="rounded border border-border-primary bg-white p-4 shadow-sm">
                    <div class="flex items-start gap-2">
                        @unless ($isRead)
                            <span class="mt-1.5 h-2 w-2 shrink-0 rounded-full bg-heritage-yellow" aria-hidden="true"></span>
                        @endunless
                        <div class="min-w-0 flex-1">
                            <p @class(['text-sm text-charcoal', 'font-semibold' => ! $isRead, 'font-medium' => $isRead])>
                                {{ $announcement->title }}
                            </p>
                            @if ($announcement->body)
                                <p class="mt-1 whitespace-pre-line text-sm text-charcoal/70">{{ $announcement->body }}</p>
                            @endif
                            @if ($announcement->last_message_at)
                                <p class="mt-2 text-xs text-charcoal/50">{{ $announcement->last_message_at }}</p>
                            @endif
                        </div>
                    </div>
                </li>
            @endforeach
        </ul>
    @endif
</div>
