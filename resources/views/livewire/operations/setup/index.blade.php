<div class="space-y-6">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.setup_screen.title') }}</h1>
        <p class="mt-1 max-w-3xl text-sm text-slate-600">{{ __('opes.setup_screen.intro') }}</p>
    </header>

    @if ($ready)
        <p class="rounded border border-heritage-green/40 bg-heritage-green/10 p-3 text-sm text-heritage-green" role="status">
            {{ __('opes.setup_screen.ready') }}
        </p>
    @else
        <p class="rounded border border-heritage-red/40 bg-heritage-red/10 p-3 text-sm text-heritage-red" role="alert">
            {{ __('opes.setup_screen.not_ready', ['count' => $blocked]) }}
        </p>
    @endif

    <section class="space-y-3">
        @foreach ($checks as $check)
            @php
                $tone = match ($check['status']->value) {
                    'pass' => 'border-heritage-green/40 bg-heritage-green/5',
                    'blocked' => 'border-heritage-red/50 bg-heritage-red/5',
                    default => 'border-amber-400/50 bg-amber-50',
                };
                $badge = match ($check['status']->value) {
                    'pass' => 'bg-heritage-green text-white',
                    'blocked' => 'bg-heritage-red text-white',
                    default => 'bg-amber-500 text-white',
                };
            @endphp

            <article class="rounded-lg border {{ $tone }} p-4 shadow-sm">
                <div class="flex flex-wrap items-start justify-between gap-2">
                    <h2 class="text-sm font-semibold text-charcoal">{{ $check['title'] }}</h2>
                    <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $badge }}">
                        {{ $check['status']->label() }}
                    </span>
                </div>

                <p class="mt-1 font-mono text-xs text-slate-700">{{ $check['detail'] }}</p>

                @if ($check['status'] !== \App\Modules\Operations\Domain\SetupCheckStatus::Pass)
                    <p class="mt-2 text-sm text-slate-700">{{ $check['remedy'] }}</p>
                    <p class="mt-1 text-xs text-slate-500">
                        {{ __('opes.setup_screen.owner') }}: <strong>{{ $check['owner'] }}</strong>
                    </p>
                    @if ($check['fix_href'] !== null)
                        <a href="{{ $check['fix_href'] }}"
                           class="mt-2 inline-block text-sm font-medium text-primary hover:underline">
                            {{ __('opes.setup_screen.fix_this') }}
                        </a>
                    @endif
                @endif
            </article>
        @endforeach
    </section>
</div>
