@php
    $panels = [
        [__('opes.guardian_portal.auth_welcome_step_1'), __('opes.guardian_portal.auth_welcome_step_1_body'), 'users'],
        [__('opes.guardian_portal.auth_welcome_step_2'), __('opes.guardian_portal.auth_welcome_step_2_body'), 'chart'],
        [__('opes.guardian_portal.auth_welcome_step_3'), __('opes.guardian_portal.auth_welcome_step_3_body'), 'chat'],
    ];
@endphp

<div>
    {{-- ---------------------------------------------------------- splash -- --}}
    @if ($view === 'splash')
        <div class="flex min-h-screen flex-col items-center justify-center bg-portal-green px-6 text-center">
            <x-portal.crest size="xl"/>

            <p class="mt-5 font-serif text-3xl font-bold tracking-[0.22em] text-portal-gold">
                {{ __('opes.shell.brand') }}
            </p>
            <p class="mt-1 text-xs font-semibold tracking-[0.25em] text-white/70">
                {{ __('opes.guardian_portal.brand_suffix') }}
            </p>
            <p class="mt-4 text-sm text-white/80">{{ __('opes.guardian_portal.tagline') }}</p>

            <p class="mt-10 text-xs text-white/50">{{ __('opes.guardian_portal.auth_splash_loading') }}</p>

            <a href="{{ route('login') }}"
               class="mt-6 rounded-xl bg-portal-gold px-6 py-2.5 text-sm font-bold text-charcoal">
                {{ __('opes.guardian_portal.auth_sign_in') }}
            </a>
        </div>

    {{-- --------------------------------------------------------- welcome -- --}}
    @elseif ($view === 'welcome')
        <x-portal.auth-frame :title="__('opes.guardian_portal.auth_welcome_title')">
            @php [$panelTitle, $panelBody, $panelIcon] = $panels[$step]; @endphp

            <div class="rounded-2xl border border-border-primary bg-white p-6 text-center shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
                <x-portal.icon :name="$panelIcon" tone="primary" size="lg" class="mx-auto"/>

                <p class="mt-4 text-lg font-bold text-charcoal">{{ $panelTitle }}</p>
                <p class="mt-2 text-sm leading-relaxed text-charcoal/70">{{ $panelBody }}</p>

                <div class="mt-6 flex items-center justify-center gap-2" role="tablist">
                    @foreach ($panels as $index => $panel)
                        <button type="button" wire:click="goTo({{ $index }})"
                                aria-label="{{ $panel[0] }}"
                                @class([
                                    'h-2 rounded-full transition-all',
                                    'w-6 bg-portal-gold' => $index === $step,
                                    'w-2 bg-border-strong' => $index !== $step,
                                ])></button>
                    @endforeach
                </div>

                <div class="mt-6">
                    @if ($step < 2)
                        <button type="button" wire:click="next"
                                class="w-full rounded-xl bg-portal-green px-4 py-3 text-sm font-semibold text-white">
                            {{ __('opes.common.next') ?? __('opes.guardian_portal.fees_pay_continue') }}
                        </button>
                    @else
                        <a href="{{ route('login') }}"
                           class="block w-full rounded-xl bg-portal-green px-4 py-3 text-center text-sm font-semibold text-white">
                            {{ __('opes.guardian_portal.auth_welcome_start') }}
                        </a>
                    @endif
                </div>
            </div>
        </x-portal.auth-frame>

    {{-- ----------------------------------------------------------- reset -- --}}
    @elseif ($view === 'reset')
        <x-portal.auth-frame :title="__('opes.guardian_portal.auth_reset_title')"
                             :subtitle="__('opes.guardian_portal.auth_reset_subtitle')">
            {{-- No form. This platform sends no password emails - the login
                 screen already says so - and a reset field that posted nowhere
                 would be worse than a sentence telling the parent what to do. --}}
            <div class="rounded-2xl border border-border-primary bg-white p-6 shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
                <div class="flex items-start gap-3">
                    <x-portal.icon name="shield" tone="primary"/>
                    <p class="text-sm leading-relaxed text-charcoal/75">
                        {{ __('opes.guardian_portal.auth_reset_body') }}
                    </p>
                </div>

                <a href="{{ route('login') }}"
                   class="mt-6 block w-full rounded-xl bg-portal-green px-4 py-3 text-center text-sm font-semibold text-white">
                    {{ __('opes.guardian_portal.auth_back_to_login') }}
                </a>
            </div>
        </x-portal.auth-frame>

    {{-- ------------------------------------------------------------- otp -- --}}
    @else
        <x-portal.auth-frame :title="__('opes.guardian_portal.auth_otp_title')"
                             :subtitle="__('opes.guardian_portal.auth_otp_subtitle')">
            <div class="rounded-2xl border border-border-primary bg-white p-6 shadow-[0_2px_10px_rgba(0,45,23,0.06)]">
                {{-- The six boxes the design shows, rendered as the disabled
                     things they are. 2FA is a spec 1 non-goal: there is no
                     endpoint to verify a code against, and a live-looking field
                     would teach parents a security step that does not exist. --}}
                <div class="flex justify-between gap-2" aria-hidden="true">
                    @for ($i = 0; $i < 6; $i++)
                        <span class="flex h-14 flex-1 items-center justify-center rounded-xl border border-border-primary bg-surface-secondary text-xl font-bold text-charcoal/25">
                            &middot;
                        </span>
                    @endfor
                </div>

                <div class="mt-5 flex items-start gap-3">
                    <x-portal.icon name="help" tone="primary" size="sm"/>
                    <p class="text-sm leading-relaxed text-charcoal/70">
                        {{ __('opes.guardian_portal.auth_otp_body') }}
                    </p>
                </div>

                <a href="{{ route('login') }}"
                   class="mt-6 block w-full rounded-xl bg-portal-green px-4 py-3 text-center text-sm font-semibold text-white">
                    {{ __('opes.guardian_portal.auth_back_to_login') }}
                </a>
            </div>
        </x-portal.auth-frame>
    @endif
</div>
