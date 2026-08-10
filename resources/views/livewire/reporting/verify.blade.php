{{-- /documents/verify (10-documents §17.2). No dedicated mockup exists, so the
     chrome mirrors the established card/pill language of the Phase 8-10
     screens: white card on sand, x-status-pill for the four states, the word
     carrying the meaning and colour only reinforcing it. Every failure mode
     renders the SAME generic NOT FOUND card - no separate "bad signature"
     message anywhere in this template, by design. --}}

<div class="mx-auto min-w-0 max-w-3xl space-y-4">
    <header>
        <h1 class="text-lg font-semibold text-charcoal">{{ __('verify.title') }}</h1>
        <p class="mt-1 text-sm text-charcoal/70">{{ __('verify.subtitle') }}</p>
    </header>

    <section aria-label="{{ __('verify.token_label') }}"
             class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
        <form wire:submit="check" class="space-y-3">
            <label for="verify-token" class="flex flex-col gap-1">
                <span class="text-xs font-medium text-charcoal/70">{{ __('verify.token_label') }}</span>
                <textarea id="verify-token" rows="3" wire:model="token"
                          placeholder="{{ __('verify.token_placeholder') }}"
                          class="rounded border border-border-primary bg-white px-3 py-1.5 font-mono text-sm text-charcoal focus:border-primary/50"></textarea>
                @error('token')
                    <span class="text-xs text-heritage-red">{{ $message }}</span>
                @enderror
            </label>

            <div class="flex justify-end">
                <button type="submit"
                        class="rounded bg-primary px-4 py-1.5 text-sm font-semibold text-white hover:bg-primary/90">
                    {{ __('verify.check') }}
                </button>
            </div>
        </form>
    </section>

    @if (! $checked)
        <p class="text-sm text-charcoal/50">{{ __('verify.empty_hint') }}</p>
    @elseif ($result !== null && $result['status'] === 'not_found')
        <section aria-label="{{ __('verify.status_not_found') }}"
                 class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <x-status-pill status="red" :label="__('verify.status_not_found')"/>
            <p class="mt-3 text-sm text-charcoal/70">{{ __('verify.not_found_help') }}</p>
        </section>
    @elseif ($result !== null)
        @php
            $tone = match ($result['status']) {
                'valid' => 'ok',
                'superseded' => 'amber',
                default => 'red',
            };
            $label = __('verify.status_'.$result['status']);
        @endphp

        <section aria-label="{{ $label }}"
                 class="rounded-lg border border-border-primary bg-white p-4 shadow-sm sm:p-5">
            <div class="flex items-center justify-between gap-3">
                <x-status-pill :status="$tone" :label="$label"/>
                <span class="font-mono text-sm text-charcoal">{{ $result['serial'] }}</span>
            </div>

            @if ($result['status'] === 'revoked')
                <p class="mt-3 text-sm text-heritage-red">{{ __('verify.revoked_help') }}</p>
            @elseif ($result['status'] === 'superseded' && $result['superseded_by'] !== null)
                <p class="mt-3 text-sm text-charcoal/70">
                    {{ __('verify.superseded_help', ['serial' => $result['superseded_by']]) }}
                </p>
            @endif

            <dl class="mt-4 grid grid-cols-1 gap-x-6 gap-y-2 text-sm sm:grid-cols-2">
                <div class="flex flex-col">
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('verify.detail_template') }}</dt>
                    <dd class="text-charcoal">
                        {{ app()->getLocale() === 'fr' ? $result['template_fr'] : $result['template'] }}
                    </dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('verify.detail_serial') }}</dt>
                    <dd class="font-mono text-charcoal">{{ $result['serial'] }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('verify.detail_issued_on') }}</dt>
                    <dd class="text-charcoal">{{ $result['issued_on'] }}</dd>
                </div>
                <div class="flex flex-col">
                    <dt class="text-xs font-medium text-charcoal/60">{{ __('verify.detail_issuer') }}</dt>
                    <dd class="text-charcoal">{{ $result['issuer'] }}</dd>
                </div>
                @if ($result['superseded_by'] !== null)
                    <div class="flex flex-col">
                        <dt class="text-xs font-medium text-charcoal/60">{{ __('verify.detail_superseded_by') }}</dt>
                        <dd class="font-mono text-charcoal">{{ $result['superseded_by'] }}</dd>
                    </div>
                @endif
            </dl>
        </section>
    @endif
</div>
