<div class="min-w-0 space-y-5">
    <div class="min-w-0">
        <a href="{{ route('portal.account') }}"
           class="inline-flex items-center gap-1 text-xs font-medium text-charcoal/60 hover:text-primary">
            <svg class="h-3.5 w-3.5" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" d="M15 19l-7-7 7-7"/>
            </svg>
            {{ __('opes.guardian_portal.account_title') }}
        </a>

        <h1 class="mt-1 text-2xl font-bold text-charcoal">{{ __('opes.guardian_portal.help_title') }}</h1>
    </div>

    {{-- The answers are written against what this product actually does. The
         commonest call this portal will generate is "why can't I see my
         child's fees", and the true answer is that the school controls it per
         guardian - "try refreshing" would send that parent round in circles. --}}
    <section aria-labelledby="portal-help-faq" class="rounded-2xl border border-border-primary bg-white shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
        <h2 id="portal-help-faq" class="border-b border-border-secondary px-4 py-3 text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.help_title') }}
        </h2>

        <ul class="divide-y divide-border-secondary">
            @foreach ($faqs as $index => $faq)
                <li wire:key="faq-{{ $index }}">
                    <button type="button" wire:click="toggle({{ $index }})"
                            aria-expanded="{{ $open === $index ? 'true' : 'false' }}"
                            class="flex w-full items-center gap-3 px-4 py-3 text-left hover:bg-surface-secondary">
                        <span class="min-w-0 flex-1 text-sm font-medium text-charcoal">{{ $faq['q'] }}</span>
                        <span class="shrink-0 text-lg leading-none text-primary" aria-hidden="true">{{ $open === $index ? '−' : '+' }}</span>
                    </button>

                    @if ($open === $index)
                        <p class="px-4 pb-3 text-sm leading-relaxed text-charcoal/70">{{ $faq['a'] }}</p>
                    @endif
                </li>
            @endforeach
        </ul>
    </section>

    <section aria-labelledby="portal-help-contact" class="rounded-2xl border border-border-primary bg-white shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
        <h2 id="portal-help-contact" class="border-b border-border-secondary px-4 py-3 text-sm font-semibold text-charcoal">
            {{ __('opes.guardian_portal.help_contact') }}
        </h2>

        <ul class="divide-y divide-border-secondary">
            <li>
                <a href="{{ route('portal.messages') }}" class="flex items-center gap-3 px-4 py-3 hover:bg-surface-secondary">
                    <span class="min-w-0 flex-1 text-sm text-charcoal">{{ __('opes.guardian_portal.help_message') }}</span>
                    <svg class="h-4 w-4 shrink-0 text-charcoal/40" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 5l7 7-7 7"/>
                    </svg>
                </a>
            </li>
        </ul>
    </section>
</div>
