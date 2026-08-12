{{-- `/portal/announcements` - built to mobile/school-announcements.png. --}}
<div class="min-w-0 space-y-5">

    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon name="megaphone" tone="primary"/>
        <div class="min-w-0">
            <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.announcements_title') }}</h1>
        </div>
    </div>

    @if ($announcements->isEmpty())
        <x-portal.card>
            <div class="flex flex-col items-center gap-3 py-6 text-center">
                <x-portal.icon name="megaphone" tone="primary" size="lg"/>
                <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.announcements_empty') }}</p>
            </div>
        </x-portal.card>
    @else
        <div class="space-y-3">
            @foreach ($announcements as $announcement)
                @php
                    $isRead = $announcement->first_message_id !== null
                        && (int) ($announcement->last_read_message_id ?? 0) >= (int) $announcement->first_message_id;
                    $at = $announcement->last_message_at === null
                        ? null
                        : \Illuminate\Support\Carbon::parse($announcement->last_message_at);
                @endphp

                <x-portal.card wire:key="ann-{{ $announcement->id }}" :tone="$isRead ? 'white' : 'green'">
                    <div class="flex items-start gap-3">
                        {{-- The date chip the design uses on activity rows. --}}
                        <span class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-white">
                            <span class="text-[10px] font-semibold uppercase text-charcoal/50">
                                {{ $at?->translatedFormat('M') ?? '—' }}
                            </span>
                            <span class="text-base font-bold leading-none text-charcoal">
                                {{ $at?->format('j') ?? '' }}
                            </span>
                        </span>

                        <div class="min-w-0 flex-1">
                            <div class="flex items-center gap-2">
                                <p @class([
                                    'text-sm text-charcoal',
                                    'font-bold' => ! $isRead,
                                    'font-medium' => $isRead,
                                ])>{{ $announcement->title }}</p>

                                @unless ($isRead)
                                    <span class="h-2 w-2 shrink-0 rounded-full bg-portal-gold" aria-hidden="true"></span>
                                @endunless
                            </div>

                            @if ($announcement->body)
                                <p class="mt-1 whitespace-pre-line text-sm text-charcoal/70">{{ $announcement->body }}</p>
                            @endif

                            @if ($at)
                                <p class="mt-2 text-xs text-charcoal/50">{{ $at->translatedFormat('j F Y') }}</p>
                            @endif
                        </div>
                    </div>
                </x-portal.card>
            @endforeach
        </div>
    @endif
</div>
