{{-- /settings - the categorised hub. Every card is permission-filtered in the
     component, so anything rendered here is a link the holder can actually
     follow. --}}
<div class="min-w-0 space-y-6">
    <div>
        <h1 class="text-2xl font-bold text-charcoal">{{ __('opes.settings_hub.title') }}</h1>
        <p class="mt-1 text-sm text-text-secondary">{{ __('opes.settings_hub.subtitle') }}</p>
    </div>

    <div class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
        @foreach ($cards as $card)
            <a href="{{ route($card['route']) }}"
               class="group flex min-w-0 flex-col rounded-xl border border-border-primary bg-white p-5 shadow-sm transition hover:border-primary hover:shadow-md">
                <span class="mb-3 inline-flex h-10 w-10 items-center justify-center rounded-lg bg-kpi-green text-kpi-green-solid">
                    <x-opes-nav-icon :nav-key="$card['icon']" class="h-5 w-5"/>
                </span>
                <span class="text-base font-semibold text-charcoal group-hover:text-primary">{{ $card['title'] }}</span>
                <span class="mt-1 text-sm text-text-secondary">{{ $card['description'] }}</span>
                @if ($card['summary'] !== '')
                    <span @class([
                        'mt-3 inline-flex w-fit rounded-full px-2.5 py-1 text-xs font-medium',
                        'bg-success-bg text-success-text' => $card['tone'] === 'good',
                        'bg-warning-bg text-warning-text' => $card['tone'] === 'warn',
                        'bg-sand text-text-secondary' => $card['tone'] === 'neutral',
                    ])>{{ $card['summary'] }}</span>
                @endif
            </a>
        @endforeach
    </div>

    {{-- The reference's System Status panel, on the screen it actually shows
         it on. An equivalent lives on /settings/advanced beside the raw
         key/value browser; this is the one an administrator lands on.

         Every line states its condition in WORDS as well as a dot -
         "Connected", "Never", a version string. A coloured dot alone is not a
         reading, and "Last backup: Never" is precisely the line that must not
         be able to hide behind a green circle. --}}
    <section aria-labelledby="opes-settings-status"
             class="rounded-xl border border-shell-divider bg-shell-surface px-4 py-3 shadow-[0_1px_2px_rgba(16,24,40,0.05)]">
        <h2 id="opes-settings-status" class="text-[15px] font-semibold text-charcoal">
            {{ __('opes.settings_screen.rail_system_status') }}
        </h2>

        <dl class="mt-2 grid gap-x-6 gap-y-1 sm:grid-cols-2 lg:grid-cols-4">
            @foreach ($systemStatus as $line)
                <div class="flex items-center gap-2.5 border-b border-shell-divider py-[7px] last:border-0 sm:border-0">
                    <span class="h-2 w-2 shrink-0 rounded-full {{ $line['ok'] ? 'bg-success' : 'bg-shell-alert' }}"
                          aria-hidden="true"></span>
                    <dt class="min-w-0 flex-1 truncate text-[13px] text-charcoal">{{ $line['label'] }}</dt>
                    <dd class="shrink-0 text-[12px] {{ $line['ok'] ? 'text-charcoal/65' : 'font-semibold text-danger-text' }}">
                        {{ $line['value'] }}
                    </dd>
                </div>
            @endforeach
        </dl>
    </section>
</div>
