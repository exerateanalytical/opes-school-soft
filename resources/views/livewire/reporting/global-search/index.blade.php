<div class="relative w-full" x-data="{ open: @entangle('open') }" x-on:click.outside="open = false">
    <label for="opes-search" class="sr-only">{{ __('opes.shell.search') }}</label>
    <div class="relative">
        <svg class="pointer-events-none absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-charcoal/40"
             viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
            <circle cx="11" cy="11" r="7"/>
            <path stroke-linecap="round" d="M21 21l-4.3-4.3"/>
        </svg>
        <input id="opes-search" type="search" wire:model.live.debounce.300ms="query"
               placeholder="{{ __('opes.shell.search') }}"
               autocomplete="off"
               class="w-full rounded-full border border-sand bg-white py-1.5 pl-9 pr-3 text-sm text-charcoal placeholder:text-charcoal/40 focus:border-primary focus:outline-none">
    </div>

    <div x-show="open" x-cloak
         class="absolute left-0 right-0 z-40 mt-1 max-h-96 overflow-y-auto rounded-lg border border-sand bg-white shadow-lg">
        @forelse ($results as $result)
            <a href="{{ $result['url'] }}"
               class="block border-t border-sand px-3 py-2 text-sm first:border-t-0 hover:bg-sand/40">
                <span class="block font-medium text-charcoal">{{ $result['label'] }}</span>
                <span class="block text-xs text-slate-500">
                    {{ __('opes.global_search.group_'.$result['group']) }}
                    @if ($result['sublabel'] !== '') · {{ $result['sublabel'] }} @endif
                </span>
            </a>
        @empty
            <p class="px-3 py-4 text-center text-sm text-slate-500">{{ __('opes.global_search.no_results') }}</p>
        @endforelse
    </div>
</div>
