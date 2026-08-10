<div class="space-y-3">
    <header>
        <h1 class="text-xl font-semibold text-charcoal">{{ __('opes.unfinished_work.title') }}</h1>
        <p class="mt-1 text-sm text-slate-600">{{ __('opes.unfinished_work.intro') }}</p>
    </header>

    <ul class="space-y-2">
        @forelse ($held as $item)
            <li class="flex items-center justify-between gap-3 rounded-lg border border-border-primary bg-white p-4 shadow-sm">
                <div>
                    <span class="block font-medium text-charcoal">{{ $item['label'] }}</span>
                    <span class="block text-xs text-slate-500">
                        {{ __('opes.unfinished_work.held') }} {{ $item['held_at']?->diffForHumans() }}
                    </span>
                </div>
                <div class="flex shrink-0 gap-2">
                    @if ($item['url'])
                        <a href="{{ $item['url'] }}"
                           class="rounded bg-primary px-3 py-1.5 text-sm font-semibold text-white">
                            {{ __('opes.unfinished_work.resume') }}
                        </a>
                    @endif
                    <button type="button" wire:click="discard({{ $item['id'] }})"
                            wire:confirm="{{ __('opes.unfinished_work.confirm_discard') }}"
                            class="rounded border border-border-primary px-3 py-1.5 text-sm text-slate-600 hover:bg-sand/40">
                        {{ __('opes.unfinished_work.discard') }}
                    </button>
                </div>
            </li>
        @empty
            <li class="rounded-lg border border-dashed border-border-primary p-8 text-center text-sm text-slate-500">
                {{ __('opes.unfinished_work.empty') }}
            </li>
        @endforelse
    </ul>
</div>
