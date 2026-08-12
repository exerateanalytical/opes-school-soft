@php
    $titles = [
        'activities' => __('opes.guardian_portal.activities_title'),
        'excursions' => __('opes.guardian_portal.activities_excursions'),
        'sports' => __('opes.guardian_portal.activities_sports'),
        'detail' => __('opes.guardian_portal.activities_detail'),
        'school' => __('opes.guardian_portal.school_info_title'),
    ];
@endphp

<div class="min-w-0 space-y-5">
    <div class="flex items-center gap-3 pt-2">
        <x-portal.icon :name="$view === 'school' ? 'pin' : 'megaphone'" tone="primary"/>
        <h1 class="text-2xl font-bold text-charcoal">{{ $titles[$view] }}</h1>
    </div>

    @if ($view !== 'detail')
        <div class="-mx-4 overflow-x-auto px-4 sm:mx-0 sm:px-0">
            <div class="inline-flex gap-2">
                @foreach ([
                    'activities' => 'megaphone',
                    'excursions' => 'pin',
                    'sports' => 'chart',
                    'school' => 'id',
                ] as $key => $icon)
                    <a href="{{ route('portal.school-life', $key) }}"
                       @if ($view === $key) aria-current="page" @endif
                       @class([
                           'flex shrink-0 items-center gap-2 rounded-xl border px-3.5 py-2.5 text-sm font-semibold',
                           'border-portal-green bg-portal-green text-white' => $view === $key,
                           'border-border-primary bg-white text-charcoal/70 hover:border-primary/40' => $view !== $key,
                       ])>
                        <x-portal.icon :name="$icon" bare size="sm"/>
                        {{ $titles[$key] }}
                    </a>
                @endforeach
            </div>
        </div>
    @endif

    {{-- ---------------------------------------------------- school info -- --}}
    @if ($view === 'school')
        <x-portal.card :padded="false">
            <div class="rounded-t-2xl bg-portal-green px-4 py-6 text-center">
                <x-portal.crest size="lg" class="mx-auto"/>
                <p class="mt-3 text-base font-bold tracking-[0.15em] text-portal-gold">{{ $schoolName }}</p>
                <p class="mt-1 text-xs text-white/70">{{ __('opes.guardian_portal.tagline') }}</p>
            </div>

            <dl class="divide-y divide-border-secondary px-4 py-2 text-sm sm:px-5">
                @foreach ([
                    ['id', __('opes.guardian_portal.school_info_type'), 'Bilingual secondary'],
                    ['pin', __('opes.guardian_portal.school_info_region'), 'Centre, Cameroon'],
                    ['calendar', __('opes.guardian_portal.school_info_year'), $academicYear ?? '—'],
                ] as [$icon, $label, $value])
                    <div class="flex items-center gap-3 py-3">
                        <x-portal.icon :name="$icon" tone="primary" size="sm"/>
                        <dt class="min-w-0 flex-1 text-charcoal/70">{{ $label }}</dt>
                        <dd class="shrink-0 font-semibold text-charcoal">{{ $value }}</dd>
                    </div>
                @endforeach
            </dl>
        </x-portal.card>

        <x-portal.card :padded="false">
            <div class="p-4 sm:p-5">
                <x-portal.section :title="__('opes.guardian_portal.contacts_school')" icon="phone"/>
            </div>

            <div class="divide-y divide-border-secondary pb-1">
                <x-portal.row :title="__('opes.guardian_portal.help_message')" icon="chat" tone="primary"
                              :href="route('portal.messages')"/>
                <x-portal.row :title="__('opes.guardian_portal.help_title')" icon="help" tone="primary"
                              :href="route('portal.help')"/>
            </div>
        </x-portal.card>

    {{-- --------------------------------------------------- activity detail -- --}}
    @elseif ($view === 'detail')
        <a href="{{ route('portal.school-life', 'activities') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.2"
                 stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.activities_title') }}
        </a>

        @if ($activity === null)
            <x-portal.card>
                <div class="flex flex-col items-center gap-3 py-6 text-center">
                    <x-portal.icon name="megaphone" tone="primary" size="lg"/>
                    <p class="text-sm text-charcoal/60">{{ __('opes.guardian_portal.announcements_empty') }}</p>
                </div>
            </x-portal.card>
        @else
            @php
                $at = $activity->last_message_at === null
                    ? null
                    : \Illuminate\Support\Carbon::parse($activity->last_message_at);
            @endphp

            <x-portal.card>
                <div class="flex items-start gap-3">
                    <span class="flex h-14 w-14 shrink-0 flex-col items-center justify-center rounded-xl bg-portal-tint">
                        <span class="text-[10px] font-semibold uppercase text-charcoal/50">{{ $at?->translatedFormat('M') ?? '—' }}</span>
                        <span class="text-lg font-bold leading-none text-charcoal">{{ $at?->format('j') ?? '' }}</span>
                    </span>

                    <div class="min-w-0 flex-1">
                        <h2 class="text-lg font-bold text-charcoal">{{ $activity->title }}</h2>
                        @if ($at)
                            <p class="mt-0.5 text-sm text-charcoal/60">{{ $at->translatedFormat('l j F Y') }}</p>
                        @endif
                    </div>
                </div>

                @if ($activity->body)
                    <p class="mt-4 whitespace-pre-line text-sm leading-relaxed text-charcoal/80">{{ $activity->body }}</p>
                @endif
            </x-portal.card>

            {{-- No RSVP, no permission slip. Consent for an excursion is a legal
                 record and there is no write endpoint for one. --}}
            <x-portal.card tone="green" class="flex items-start gap-3">
                <x-portal.icon name="help" tone="primary" size="sm"/>
                <p class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.activities_note') }}</p>
            </x-portal.card>
        @endif

    {{-- --------------------------------- activities / excursions / sports -- --}}
    @else
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
                        $at = $announcement->last_message_at === null
                            ? null
                            : \Illuminate\Support\Carbon::parse($announcement->last_message_at);
                    @endphp

                    <a href="{{ route('portal.school-life.detail', $announcement->id) }}" class="block">
                        <x-portal.card wire:key="act-{{ $announcement->id }}"
                                       class="flex items-start gap-3 hover:border-primary/40">
                            <span class="flex h-12 w-12 shrink-0 flex-col items-center justify-center rounded-xl bg-portal-tint">
                                <span class="text-[10px] font-semibold uppercase text-charcoal/50">{{ $at?->translatedFormat('M') ?? '—' }}</span>
                                <span class="text-base font-bold leading-none text-charcoal">{{ $at?->format('j') ?? '' }}</span>
                            </span>

                            <span class="min-w-0 flex-1">
                                <span class="block text-sm font-semibold text-charcoal">{{ $announcement->title }}</span>
                                @if ($announcement->body)
                                    <span class="mt-1 block line-clamp-2 text-xs text-charcoal/65">{{ $announcement->body }}</span>
                                @endif
                            </span>

                            <svg class="h-4 w-4 shrink-0 text-charcoal/30" viewBox="0 0 24 24" fill="none" stroke="currentColor"
                                 stroke-width="2.2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                                <path d="M9 5l7 7-7 7"/>
                            </svg>
                        </x-portal.card>
                    </a>
                @endforeach
            </div>
        @endif

        <x-portal.card tone="green" class="flex items-start gap-3">
            <x-portal.icon name="help" tone="primary" size="sm"/>
            <p class="text-sm text-charcoal/70">{{ __('opes.guardian_portal.activities_note') }}</p>
        </x-portal.card>
    @endif
</div>
