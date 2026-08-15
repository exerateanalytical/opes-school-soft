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
</div>
